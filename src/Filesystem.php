<?php

declare(strict_types=1);

namespace Filesystem;

use Filesystem\Exception\FileNotFoundException;
use Filesystem\Exception\RTException;
use Path\Path;

class Filesystem implements FilesystemInterface
{
  private const MAX_PATHLEN = \PHP_MAXPATHLEN - 2; 

  /**
   * 
   * 
   * @param string $directory
   * @param int    $order
   * @param bool   $traversal
   * 
   * @return array|false
   */
  public function scandir(string $directory, bool $traversal = false, int $order = 0)
  {
    if (!$this->isDir($directory)) {
      return false;
    }

    $output = $this->exec('scandir', $directory, $order);

    $traversal ? ($output=$this->exec('array_diff', $output, ['.', '..'])) : $output;
    return $output;
  }

  /**
   * 
   * 
   * @param string $phpfunc
   * @param mixed  $args
   * 
   * @return mixed
   */
  private function exec(string $phpfunc, ...$args)
  {
    $this->throwIfFunctionNotExists($phpfunc);
    set_error_handler(self::errorHandler(...));
    try {return $phpfunc(...$args);} finally {restore_error_handler();}
  }

  /**
   * 
   * 
   * @param string $dirname
   * @return int
   */
  public function dirsize(string $dirname) : int
  {
    return $this->filesize($this->scan($dirname, 0, 1, false));
  }

  /**
   * 
   * 
   * @param string|iterable $files
   */
  public function create($files)
  {
    foreach($this->toIterable($files) as $file) {
      $dir = Path::dirname($file);
      !$this->isDir($dir) && $this->mkdir($dir);
      $this->touch($file);
    }
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return void
   */
  public function remove($files) : void
  {
    $files = $files instanceof \Traversable ? iterator_to_array($files) : $this->toIterable($files);
    $this->delete($files, false);
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return int
   */
  public function filesize($files) : int
  {
    // Throw RTException error if file does not exists.
    $this->throwIfFileNotFound($files);

    $filesize = 0;

    foreach($this->toIterable($files) as $file) {
      $filesize += $this->exec('filesize', $file);
    }

    return $filesize;
  }

  
  
  /**
   * 
   * 
   * @param int    $type
   * @param string $msg
   * 
   * @return void
   */
  private static function errorHandler(int $type, string $msg) : void
  {

  }

  /**
   * 
   * 
   * @param string $directory
   * @param bool   $traversal
   * @param int    $order
   * @param int    $filter
   * @param bool   $isTree
   * 
   * @return array|false
   */
  public function scan(string $directory, int $order = 0, int $filter = 0, bool $isTree = true)
  {
    // Check if the directory exists and return false;
    if (!$this->isDir($directory)) {
      return false;
    }

    if ($filter > 3 || $filter < 0) {
      throw new RTException(\sprintf('Cannot scan directory Invalid filter value [%d].', $filter));
    }

    $output  = [];
    $isBreak = $filter === 3 && $filter--;
    $filters = ['exists', 'isFile', 'isDir'];
    $fMethod = $filters[$filter];

    $this->scanRecursive($directory, $order, [$this, $fMethod], $isBreak, $isTree, $output, $directory);
    return $output;
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function isEmptyDir($files) : bool
  {
    return !$this->isDir($files) || @empty($this->scandir($files, true));
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return iterable
   */
  private function toIterable($files)
  {
    return \is_iterable($files) ? $files : [$files];
  }

  /**
   * 
   * 
   * @param string $from
   * @param string $toDir
   * @param bool   $overwrite
   * 
   * @return void
   */
  public function move(string $from, string $toDir, $overwrite = false) : void
  {
    if (!$this->isDir($toDir)) {
      throw new RTException(
        sprintf('Cannot move [%s] because given Argument #2 not a directory [%s].', $from, $toDir)
      );
    }

    $this->rename($from, Path::separate([$toDir, Path::basename($from)], Path::FSEP), $overwrite);
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @param int|null        $time
   * @param int|null        $atime
   * 
   * @return void
   */
  public function touch($files, ?int $time = null, ?int $atime = null) : void
  {
    foreach($this->toIterable($files) as $file) {
      if (!($time ? $this->exec('touch', $file, $time, $atime) : $this->exec('touch', $file))) {
        throw new RTException(\sprintf('Failed to touch file [%s] at [%s].', Path::basename($file), $file));
      }
    }
  }

  /**
   * 
   * 
   * @param string   $primaryFn
   * @param callable $fallbackFn
   * 
   * @return string
   */
  private function fnForce(string $primaryFn, callable $fallbackFn) : string
  {
    return \function_exists($primaryFn) ? $primaryFn : $fallbackFn;
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function isExecutable($files) : bool
  {
    return $this->bluePrint('executable', $files);
  }

  /**
   * 
   * 
   * @param string $from
   * @param string $to
   * @param bool   $overwrite
   * 
   * @return void
   */
  public function rename(string $from, string $to, bool $overwrite = false) : void
  {
    if (!$overwrite && $this->isReadable($to)) {
      throw new RTException(\sprintf('Cannot rename because to: [%s] already exists.', $to));
    }

    if (!$this->exec('rename', $from, $to)) {
      if ($this->isDir($from)) {
        return;
      }

      throw new RTException(\sprintf('Failed cannot rename from [%s] to [%s].', $from, $to));
    }
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function isDir($files) : bool
  {
    return $this->bluePrint('dir', $files);
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function isReadable($files) : bool
  {
    return $this->bluePrint('readable', $files);
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function hasDir($files) : bool
  {
    if (!$this->isDir($files)) {
      return false;
    }

    foreach($this->toIterable($files) as $file) {
      if ($this->isDir($file)) {
        return true;
      }
    }
    return false;
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function exists($files) : bool
  {
    return $this->bluePrint('file_exists', $files, false);
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function isLink($files) : bool
  {
    return $this->bluePrint('link', $files);
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function isFile($files) : bool
  {
    return $this->bluePrint('file', $files);
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return bool
   */
  public function isWritable($files) : bool
  {
    return $this->bluePrint('writable', $files);
  }

  /**
   * 
   * 
   * @param string          $filename
   * @param string|resource $content
   * @param bool            $lock
   * 
   * @return void
   */
  public function put(string $filename, $content, bool $lock = false) : void
  {
    $dirname = Path::dirname($filename);

    if (!$this->isDir($dirname)) {
      $this->mkdir($dirname);
    }

    if (false === $this->exec('file_put_contents', $filename, $content, ($lock ? \LOCK_EX : 0))) {
      throw new RTException(\sprintf('Failed to write file [%s] at [%s].'), Path::basename($filename), $filename);
    }
  }

  /**
   * 
   * 
   * @param string $from
   * @param string $to
   * @param bool   $overwrite
   * 
   * @return void
   */
  public function copy(string $from, string $to, bool $overwrite = false) : void
  {
    Path::isLocal($from) && $this->throwIfFileNotFound($from);

    $this->mkdir(Path::dirname($to));

    $notFopened = false;
    $copyable = true;

    if (!$overwrite &&
      null === parse_url($from, \PHP_URL_HOST) && $this->isFile($to)) {
      $copyable = filemtime($from) > filemtime($to);
    }

    if (!$copyable) {
      return;
    }

    if (!($fromSource = $this->exec('fopen', $from, 'r'))) {
      $notFopened = '`from`';
    }

    if (!($toSource = $this->exec('fopen', $to, 'w', false, \stream_context_create(['ftp' => ['overwrite' => true]])))) {
      $notFopened = '`to`';
    }

    if ($notFopened) {
      throw new RTException(
        \sprintf('Failed to copy file [%s] to [%s] because source %s file could not be opened', $from, $to, $notFopened)
      );
    }

    $cbytes = stream_copy_to_stream($fromSource, $toSource);
    fclose($fromSource);
    fclose($toSource);
    unset($fromSource, $toSource);
    
    if (!$this->isFile($to)) {
      throw new RTException(\sprintf('Failed to copy file [%s] to [%s].', $from, $to));
    }

    if (Path::isLocal($from)) {
      $this->exec('chmod', $to, fileperms($to) | (fileperms($to) & 0111));
      $this->touch($to, filemtime($from));

      if ($cbytes !== $fbytes = $this->filesize($from)) {
        throw new RTException(\sprintf('Failed to copy the whole content of [%s] to [%s] (%g of %g bytes copied).', $from, $to, $cbytes, $fbytes));
      }
    }
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @param bool            $force
   * 
   * @return void
   */
  public function delete($files, bool $recursive = false, bool $force = true) : void
  {
    foreach($this->toIterable($files) as $file) {
      $force && !$this->isWritable($file) && $this->exec('chmod', $file, 0777);
      
      if ($this->isLink($file)) {
        if (!($this->exec('unlink', $file) || $this->exec('rmdir', $file)) && $this->exists($file)) {
          throw new RTException(\sprintf('Failed to remove symlink [%s].', $file));
        }
      }

      elseif ($this->isDir($file)) {

        if (!$recursive) {
          $tmp = \dirname($file).'\.!!'.base64_encode(random_bytes(3));
          $this->exists($tmp) && $this->delete($tmp, true, $force);
          if (!$this->exists($tmp) && $this->exec('rename', $file, $tmp)) {
            $file = $tmp;
          }
        }

        $this->delete($this->scan($file, 0, 3), true, $force);
        if (!$this->exec('rmdir', $file) && $this->exists($file)) {
          throw new RTException(\sprintf('Failed to remove directory [%s]', $file));
        }
      }

      else if (!$this->exec('unlink', $file)) {
        throw new RTException(\sprintf('Failed to remove file [%s] at [%s].', \basename($file), $file));
      }
    }
  }

  /**
   * 
   * 
   * @param string   $directory
   * @param bool     $traversal
   * @param int      $order
   * @param iterable $filter
   * @param bool     $isTree
   * @param array    $output
   * @param string   $parent
   * @param array    $tree
   * @param array    $tmp
   * 
   * @return void
   */
  protected function scanRecursive(string $directory, int $order, iterable $filter, bool $isBreak,
  bool $isTree, array &$output, string $parent, array &$tree = [], array $tmp = []) : void
  {
    $files = $this->scandir($directory, true, $order);
    
    if ($isBreak) {
      $output = Path::filePaths([$parent], $files);
      return;
    }

    foreach($files as $file) {
      $path = $parent.Path::FSEP.$file;

      // Handle if isFile $path
      // Store files in tmp if not isTree Otherwise Store files in tree
      if ($this->isFile($path)) {
        $isTree ? \array_push($tree, $file) : \array_push($tmp, $path);

      // Otherwise
      // Store directories in tmp and update tmp
      } else {
        \array_push($tmp, $path);
        $subfiles = [];
        $this->scanRecursive($path, $order, $isBreak, $filter, $isTree, $output, $path, $subfiles, $tmp);
        $isTree ? (($tree[$file] = $subfiles) && ($tmp = $tree)) : \array_push($tmp, ...$subfiles);
      }

      // Store the final files or directories in $output
      !$isTree && $filter($path) ? \array_push($output, $path) : $isTree && ($output = $tmp);
    }
  }

  /**
   * 
   * 
   * @param string          $suffix
   * @param string|iterable $files
   * @param string|null     $prefix
   * 
   * @return bool
   */
  private function bluePrint(string $suffix, $files, $prefix = null) : bool
  {
    $prefix = $prefix ?? 'is_';

    foreach($this->toIterable($files) as $file) {
      if (\strlen($file) > self::MAX_PATHLEN) {
        throw new RTException(\sprintf('Could not check because path length exceeds [%d] characters.', self::MAX_PATHLEN));
      }

      if (!$this->exec($prefix.$suffix, $file)) return false;
    }

    return true;
  }

  /**
   * 
   * 
   * @param string $func
   * @return void
   * 
   * @throws RTException when file does not exists.
   */
  public static function throwIfFunctionNotExists(string $func) : void
  {
    if (!\function_exists($func)) {
      throw new RTException(\sprintf('Unable to perform filesystem operation because the "%s()" function has been disabled.'), $func);
    }
  }

  /**
   * 
   * 
   * @param string|iterable $dirs
   * @param int             $perms
   * 
   * @return void
   */
  public function mkdir($dirs, int $perms = 0777) : void
  {
    foreach($this->toIterable($dirs) as $dir) {
      if ($this->isDir($dir)) {
        continue;
      }

      if (!$this->exec('mkdir', $dir, $perms, true) && !$this->isDir($dir)) {
        throw new RTException(\sprintf('Failed to create directory [%s] at [%s].'), Path::lastDir($dir), $dir);
      }
    }
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return void
   */
  private function throwIfFileNotFound($files) : void
  {
    foreach($this->toIterable($files) as $file) {
      if (!$this->isFile($file)) {
        throw new FileNotFoundException(\sprintf('File not found at the specified file [%s] at path [%s].', Path::basename($file), $file));
      }
    }
  }

  /**
   * 
   * 
   * @param string|iterable $files
   * @return void
   */
  public function empty($files)
  {
    foreach($this->toIterable($files) as $file) {
      $this->isFile($file) ? \file_put_contents($file, null, \LOCK_EX) : $this->remove($this->scan($file, 0, 3));
    }
  }
}
?>