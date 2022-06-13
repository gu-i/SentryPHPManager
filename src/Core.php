<?php

namespace SentryPHPManager
{

    use EventDispatcher\EventDispatcher;
    use Exception;
    use Psr\Cache\InvalidArgumentException;
    use Sentry\Severity;
    use Symfony\Component\Cache\Adapter\FilesystemAdapter;
    use Symfony\Component\Cache\CacheItem;
    use Symfony\Component\Yaml\Yaml;
    use Symfony\Contracts\Cache\ItemInterface;
    use function Sentry\captureException;
    use function Sentry\captureMessage;

    trait Core
    {
        private static array $config = [];

        public function sendError(string $message):
        void
        {
            self::sendMessage($message, Severity::error());
        }

        public function sendFatal(string $message):
        void
        {
            self::sendMessage($message, Severity::fatal());
        }

        public function sendInfo(string $message):
        void
        {
            self::sendMessage($message, Severity::info());
        }

        public function sendWarning(string $message):
        void
        {
            self::sendMessage($message, Severity::warning());
        }

        private static function sendMessage(string $message, string $level): void
        {
            captureMessage($message, new Severity($level));
        }

        /**
         * @throws Exception
         * @throws InvalidArgumentException
         */
        private static function boot():
        void
        {
            $cacheFactory = new FilesystemAdapter();
            $cache = $cacheFactory->getItem('sentry-manager-config');

            if (!$cache->isHit())
            {
                // Seta o timeout do cache para 1 hora.
                $cacheFactory->get('sentry-manager-config', function (ItemInterface $item)
                {
                    $item->expiresAfter(3600);
                });
                // Verifica se o arquivo de configuração existe
                // e se é possível ler.
                try
                {
                    $yml = Yaml::parseFile(__DIR__ . '\..\sentry-manager.yml');

                    if (!empty($yml) && isset($yml['sentry']['config']))
                    {
                        $cache->set($yml['sentry']['config']);
                        $cache->expiresAfter(3600);
                        $cacheFactory->save($cache);
                        EventDispatcher::get()->when('after.sentry.init', function () {
                            Sentry::get()->sendInfo('SentryPHPManager: SentryPHPManager has refreshed the configuration file.');
                        });
                    }
                    else
                    {
                        EventDispatcher::get()->when('after.sentry.init', function () {
                            captureException(new Exception('sentry-manager.yml is empty or missing "sentry" section.'));
                        });
                    }
                }
                catch (Exception $e)
                {
                    EventDispatcher::get()->when('after.sentry.init', function () use ($e) {
                        captureException($e);
                    });
                }
            }
            else
            {
                self::$config = $cache->get();
                EventDispatcher::get()->when('after.sentry.init', function () {
                    Sentry::get()->sendInfo('SentryPHPManager: SentryPHPManager has loaded the configuration file from cache.');
                });
            }
        }

        /**
         * @throws InvalidArgumentException
         */
        public static function getCache():
        CacheItem
        {
            $cacheFactory = new FilesystemAdapter();
            return $cacheFactory->getItem('sentry-manager-config');
        }

        private static function dispatch_message(string $when, string $message, string $type = 'i'):
        void
        {
            EventDispatcher::get()->when($when, function () use ($message, $type) {
                switch ($type) {
                    case 'e':
                        self::get()->sendError($message);
                        break;
                    case 'f':
                        self::get()->sendFatal($message);
                        break;
                    case 'i':
                        self::get()->sendInfo($message);
                        break;
                    case 'w':
                        self::get()->sendWarning($message);
                        break;
                    default:
                        break;
                }
            });
        }
    }
}
