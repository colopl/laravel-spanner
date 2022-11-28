<?php
/**
 * Copyright 2019 Colopl Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Colopl\Spanner;

use Exception;
use Generator;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\Traits\FilesystemCommonTrait;
use Symfony\Component\Cache\Traits\FilesystemTrait;

class FileCacheAdapter extends AbstractAdapter implements PruneableInterface
{
    use FilesystemTrait;

    /**
     * @var string
     */
    protected $prefix;

    /**
     * @var string[]
     */
    protected $pathCache = [];

    /**
     * @param string $namespace
     * @param string $directory
     * @param MarshallerInterface|null $marshaller
     */
    public function __construct(string $namespace, string $directory, MarshallerInterface $marshaller = null)
    {
        $this->marshaller = $marshaller ?? new DefaultMarshaller();
        parent::__construct($namespace);
        $this->prefix = $namespace;
        $this->directory = $directory.DIRECTORY_SEPARATOR;
        $this->ensureDirectory();
    }

    /**
     * @return void
     */
    protected function ensureDirectory()
    {
        if (! is_dir($this->directory)) {
            $umask = umask(0);
            @mkdir($this->directory, 0777, true);
            umask($umask);
            if (! is_dir($this->directory)) {
                throw new Exception(sprintf('Impossible to create the root directory "%s".', $this->directory));
            }
        }
    }

    /**
     * @param string $id
     * @param bool $mkdir
     * @return string
     */
    protected function getFile($id, $mkdir = false)
    {
        if ($mkdir) {
            $this->ensureDirectory();
        }
        return $this->resolvePath($id);
    }

    /**
     * OVERRIDE implementation from FilesystemCommonTrait since our directory structure differs from one provided
     * @see FilesystemCommonTrait::scanHashDir()
     *
     * @param string $directory
     * @return Generator
     */
    protected function scanHashDir(string $directory): Generator
    {
        if (!file_exists($directory)) {
            return;
        }

        foreach (@scandir($directory, SCANDIR_SORT_NONE) ?: [] as $file) {
            if ('.' !== $file && '..' !== $file) {
                yield $directory.\DIRECTORY_SEPARATOR.$file;
            }
        }
    }

    /**
     * @param string $id
     * @return string
     */
    protected function resolvePath($id)
    {
        if (!isset($this->pathCache[$id])) {
            $hash = str_replace('/', '-', base64_encode(hash('sha256', static::class.$id, true)));
            $path = $this->directory.$this->prefix.'-'.substr($hash, 0, 20);
            $this->pathCache[$id] = $path;
        }
        return $this->pathCache[$id];
    }
}
