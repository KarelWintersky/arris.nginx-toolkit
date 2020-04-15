<?php

namespace Arris\Toolkit;

use Exception;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class NginxToolkit implements NginxToolkitInterface
{
    /**
     * @var mixed
     */
    private static $nginx_cache_levels;

    /**
     * @var string
     */
    private static $nginx_cache_root;

    /**
     * @var string
     */
    private static $nginx_cache_key;

    /**
     * @var Logger
     */
    private static $LOGGER = null;

    /**
     * @var bool
     */
    private static $is_logging;

    /**
     * @var mixed
     */
    private static $is_using_cache;

    /**
     * @param array $options
     * - isLogging      ->env(NGINX.LOG_CACHE_CLEANING) ->default(false)
     * - isUseCache     ->env(NGINX.CACHE_USE)          ->default(false)-
     * - cache_root     ->env(NGINX.CACHE_PATH)         ->required()
     * - cache_levels   ->env(NGINX.CACHE_LEVELS)       ->default('1:2')
     * - cache_key_format env(NGINX.CACHE_KEY_FORMAT)   ->default('GET|||HOST|PATH')
     *
     * @param null $logger
     * @throws Exception
     */
    public static function init($options = [], $logger = null)
    {
        self::$LOGGER
            = $logger instanceof Logger
            ? $logger
            : (new Logger('null'))->pushHandler(new NullHandler());

        self::$is_logging = self::setOption($options, 'isLogging', false);

        self::$is_using_cache = self::setOption($options, 'isUseCache', false);

        self::$nginx_cache_root = self::setOption($options, 'cache_root', null);
        if (is_null(self::$nginx_cache_root)) {
            throw new Exception(__METHOD__ . ': required option `cache_root` not defined!');
        }
        self::$nginx_cache_root = rtrim(self::$nginx_cache_root, DIRECTORY_SEPARATOR);

        self::$nginx_cache_levels = self::setOption($options, 'cache_levels', '1:2');
        self::$nginx_cache_levels = explode(':', self::$nginx_cache_levels);

        self::$nginx_cache_key = self::setOption($options, 'cache_key_format', 'GET|||HOST|PATH');
    }

    /**
     * @param string $url
     * @return bool
     */
    public static function clear_nginx_cache(string $url)
    {
        if (self::$is_using_cache == 0) {
            return false;
        }

        if ($url === "/"):
            return self::clear_nginx_cache_entire();
        endif; // endif

        $url_parts = parse_url($url);

        $url_parts['host'] = $url_parts['host'] ?? '';
        $url_parts['path'] = $url_parts['path'] ?? '';

        $cache_key = self::$nginx_cache_key;

        $cache_key = str_replace(
            ['HOST', 'PATH'],
            [$url_parts['host'], $url_parts['path']],
            $cache_key);

        $cache_filename = md5($cache_key);

        $levels = self::$nginx_cache_levels;

        $cache_filepath = self::$nginx_cache_root;

        $offset = 0;

        foreach ($levels as $i => $level) {
            $offset -= $level;
            $cache_filepath .= "/" . substr($cache_filename, $offset, $level);
        }
        $cache_filepath .= "/{$cache_filename}";

        if (file_exists($cache_filepath)) {
            self::$LOGGER->info("NGINX Cache Force Cleaner: cached data present: ", [ $cache_filepath ]);
            $unlink_status = unlink($cache_filepath);
        } else {
            self::$LOGGER->info("NGINX Cache Force Cleaner: cached data not found: ", [ $cache_filepath ]);
            $unlink_status = true;
        }

        self::$LOGGER->info("NGINX Cache Force Cleaner: Clear status (key/status)", [$cache_key, $unlink_status]);

        return $unlink_status;
    } // -clear_nginx_cache()

    public static function clear_nginx_cache_entire()
    {
        $unlink_status = true;

        self::$LOGGER->debug("NGINX Cache Force Cleaner: requested clean whole cache");

        $dir_content = array_diff(scandir(self::$nginx_cache_root), ['.', '..']);

        foreach ($dir_content as $subdir) {
            $unlink_status = $unlink_status && self::rmdir(self::$nginx_cache_root . DIRECTORY_SEPARATOR . $subdir . '/');
        }

        self::$LOGGER->debug("NGINX Cache Force Cleaner: whole cache clean status: ", [ self::$nginx_cache_root, $unlink_status ]);

        return $unlink_status;
    }

    public static function rmdir(string $directory): bool
    {
        if (!is_dir($directory)) {
            self::$LOGGER->warning(__METHOD__ . ' throws warning: no such file or directory', [ $directory ]);
            return false;
        }

        $files = array_diff(scandir($directory), ['.', '..']);

        foreach ($files as $file) {
            (is_dir("{$directory}/{$file}"))
                ? self::rmdir("{$directory}/{$file}")
                : unlink("{$directory}/{$file}");
        }
        return rmdir($directory);
    }

    /**
     *
     * @param $options
     * @param $key
     * @param null $default_value
     * @return mixed|null
     */
    private static function setOption($options = [], $key = null, $default_value = null)
    {
        if (!is_array($options)) return $default_value;

        if (is_null($key)) return $default_value;

        return array_key_exists($key, $options) ? $options[ $key ] : $default_value;
    }
}

# -eof-
