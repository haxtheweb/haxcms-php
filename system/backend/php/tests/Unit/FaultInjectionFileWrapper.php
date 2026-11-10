<?php
/**
 * Filesystem fault injection for PHPUnit, with no production seam.
 *
 * install() swaps PHP's built-in "file" stream wrapper (plain paths) for this
 * class. Every plain-path filesystem call PHP routes through stream wrappers
 * (fopen / file_put_contents / file_get_contents / copy / rename / unlink /
 * mkdir / rmdir / scandir / opendir / file_exists / is_dir / is_file /
 * is_link / filesize / touch / chmod, and include) lands here. Each one is
 * delegated to the real filesystem unless a rule says it fails, so the code
 * under test sees the same return values and warnings it would see on a full
 * disk or a read-only directory. realpath(), glob(), symlink() and tempnam()
 * do not use stream wrappers and are never faulted.
 *
 * Rules (FaultInjectionFileWrapper::install(array(...))):
 *   op      'open'    opening the path for writing fails (fopen-time ENOSPC /
 *                     EACCES): nothing is truncated, no byte lands
 *           'write'   open succeeds (a "w" open truncates, as the OS does),
 *                     the first write stores half of its bytes, then every
 *                     write fails: a write that stops part-way
 *           'rename'  rename() with this path as source or target fails
 *           'mkdir'   mkdir() of this path fails
 *           'unlink'  unlink() of this path fails
 *   match   callable(string $absolutePath): bool
 *   errno   label used in the warning text (ENOSPC, EACCES, EXDEV)
 *   delay   optional microseconds to sleep before the operation instead of
 *           failing it (slow IO)
 *
 * Always pair install() with uninstall() in a finally block or tearDown():
 * a wrapper left registered affects every later test in the process.
 */
final class FaultInjectionFileWrapper
{
    /** @var resource|null set by PHP for stream wrappers */
    public $context;

    /** @var array<int, array> */
    private static $rules = array();
    /** @var bool */
    private static $installed = false;
    /** @var array<int, string> "op path" for every fault that fired */
    public static $fired = array();

    /** @var resource|null */
    private $handle = null;
    /** @var resource|null */
    private $dir = null;
    /** @var string */
    private $path = '';
    /** @var array|null */
    private $writeRule = null;
    /** @var bool */
    private $writeFaultTripped = false;

    public static function install(array $rules): void
    {
        self::$rules = $rules;
        self::$fired = array();
        if (!self::$installed) {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
            self::$installed = true;
        }
    }

    public static function uninstall(): void
    {
        self::$rules = array();
        if (self::$installed) {
            @stream_wrapper_restore('file');
            self::$installed = false;
        }
        clearstatcache();
    }

    public static function isInstalled(): bool
    {
        return self::$installed;
    }

    /**
     * Run $fn against the real filesystem (built-in wrapper restored).
     */
    private static function native(callable $fn)
    {
        stream_wrapper_restore('file');
        try {
            return $fn();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    private static function normalize(string $path): string
    {
        if (strncmp($path, 'file://', 7) === 0) {
            $path = substr($path, 7);
        }
        if ($path !== '' && $path[0] !== '/') {
            $path = getcwd() . '/' . $path;
        }
        return $path;
    }

    private static function rule(string $op, string $path): ?array
    {
        foreach (self::$rules as $rule) {
            if ($rule['op'] === $op && call_user_func($rule['match'], $path)) {
                if (isset($rule['delay'])) {
                    usleep((int) $rule['delay']);
                    continue;
                }
                return $rule;
            }
        }
        return null;
    }

    private static function fail(string $op, string $path, array $rule, int $options = STREAM_REPORT_ERRORS): bool
    {
        self::$fired[] = $op . ' ' . $path;
        if ($options & STREAM_REPORT_ERRORS) {
            $errno = isset($rule['errno']) ? $rule['errno'] : 'EIO';
            trigger_error($op . '(' . $path . '): injected ' . $errno, E_USER_WARNING);
        }
        return false;
    }

    // ---- streams -------------------------------------------------------

    public function stream_open($path, $mode, $options, &$openedPath)
    {
        $this->path = self::normalize($path);
        $writing = strpbrk($mode, 'waxc+') !== false;
        if ($writing && ($rule = self::rule('open', $this->path))) {
            return self::fail('open', $this->path, $rule, $options);
        }
        if ($writing) {
            $this->writeRule = self::rule('write', $this->path);
        }
        $usePath = (bool) ($options & STREAM_USE_PATH);
        $target = $this->path;
        $this->handle = self::native(function () use ($path, $mode, $usePath, $target) {
            return $usePath ? @fopen($path, $mode, true) : @fopen($target, $mode);
        });
        if ($this->handle === false) {
            $this->handle = null;
            return false;
        }
        $openedPath = $this->path;
        return true;
    }

    public function stream_write($data)
    {
        if ($this->writeRule !== null) {
            if ($this->writeFaultTripped) {
                return 0;
            }
            $this->writeFaultTripped = true;
            self::$fired[] = 'write ' . $this->path;
            $half = (int) floor(strlen($data) / 2);
            return $half > 0 ? fwrite($this->handle, substr($data, 0, $half)) : 0;
        }
        return fwrite($this->handle, $data);
    }

    public function stream_read($count)
    {
        return fread($this->handle, $count);
    }

    public function stream_close()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function stream_eof()
    {
        return feof($this->handle);
    }

    public function stream_flush()
    {
        return fflush($this->handle);
    }

    public function stream_lock($operation)
    {
        if ($operation === 0) {
            $operation = LOCK_EX;
        }
        return flock($this->handle, $operation);
    }

    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell()
    {
        return ftell($this->handle);
    }

    public function stream_stat()
    {
        return fstat($this->handle);
    }

    public function stream_truncate($size)
    {
        return ftruncate($this->handle, $size);
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }

    public function stream_cast($castAs)
    {
        return $this->handle !== null ? $this->handle : false;
    }

    public function stream_metadata($path, $option, $value)
    {
        $path = self::normalize($path);
        return self::native(function () use ($path, $option, $value) {
            switch ($option) {
                case STREAM_META_TOUCH:
                    return empty($value) ? @touch($path) : @touch($path, $value[0], $value[1]);
                case STREAM_META_OWNER_NAME:
                case STREAM_META_OWNER:
                    return @chown($path, $value);
                case STREAM_META_GROUP_NAME:
                case STREAM_META_GROUP:
                    return @chgrp($path, $value);
                case STREAM_META_ACCESS:
                    return @chmod($path, $value);
            }
            return false;
        });
    }

    // ---- paths ---------------------------------------------------------

    public function url_stat($path, $flags)
    {
        $path = self::normalize($path);
        $result = self::native(function () use ($path, $flags) {
            return ($flags & STREAM_URL_STAT_LINK) ? @lstat($path) : @stat($path);
        });
        return $result === false ? false : $result;
    }

    public function unlink($path)
    {
        $path = self::normalize($path);
        if ($rule = self::rule('unlink', $path)) {
            return self::fail('unlink', $path, $rule);
        }
        return self::native(function () use ($path) {
            return @unlink($path);
        });
    }

    public function rename($from, $to)
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if (($rule = self::rule('rename', $from)) || ($rule = self::rule('rename', $to))) {
            return self::fail('rename', $from . ' -> ' . $to, $rule);
        }
        return self::native(function () use ($from, $to) {
            return @rename($from, $to);
        });
    }

    public function mkdir($path, $mode, $options)
    {
        $path = self::normalize($path);
        if ($rule = self::rule('mkdir', $path)) {
            return self::fail('mkdir', $path, $rule, $options);
        }
        $recursive = (bool) ($options & STREAM_MKDIR_RECURSIVE);
        return self::native(function () use ($path, $mode, $recursive) {
            return @mkdir($path, $mode, $recursive);
        });
    }

    public function rmdir($path, $options)
    {
        $path = self::normalize($path);
        return self::native(function () use ($path) {
            return @rmdir($path);
        });
    }

    public function dir_opendir($path, $options)
    {
        $path = self::normalize($path);
        $this->dir = self::native(function () use ($path) {
            return @opendir($path);
        });
        if ($this->dir === false) {
            $this->dir = null;
            return false;
        }
        return true;
    }

    public function dir_readdir()
    {
        return readdir($this->dir);
    }

    public function dir_rewinddir()
    {
        rewinddir($this->dir);
        return true;
    }

    public function dir_closedir()
    {
        if (is_resource($this->dir)) {
            closedir($this->dir);
        }
        $this->dir = null;
        return true;
    }
}
