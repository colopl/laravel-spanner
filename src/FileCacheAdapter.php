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

use Generator;
use RuntimeException;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\Traits\FilesystemCommonTrait;
use Symfony\Component\Cache\Traits\FilesystemTrait;
use function is_dir;
use function mkdir;
use function scandir;
use function sprintf;
use function umask;
use const DIRECTORY_SEPARATOR;
use const SCANDIR_SORT_NONE;

class FileCacheAdapter extends AbstractAdapter implements PruneableInterface
{
    use FilesystemTrait;

    /**
     * @var string
     */
    protected string $name;

    /**
     * @param string $name
     * @param string $directory
     * @param MarshallerInterface|null $marshaller
     */
    public function __construct(string $name, string $directory, MarshallerInterface $marshaller = null)
    {
        $this->marshaller = $marshaller ?? new DefaultMarshaller();
        parent::__construct($name);
        $this->name = $name;
        $this->directory = $directory.DIRECTORY_SEPARATOR;
        $this->ensureDirectory();
    }

    protected function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            // umask must be set to 0000 inorder to create cache directory with 0777 permission.
            // writing as 0777 ensures that web-server user and cli user can both read/write to the same cache directory.
            // This is important because we want to be able to warm up the cache from cli and use it from web-server.
            $umask = umask(0);
            @mkdir($this->directory, 0777, true);
            umask($umask);
            if (!is_dir($this->directory)) {
                throw new RuntimeException(sprintf('Impossible to create the root directory "%s".', $this->directory));
            }
        }
    }

    /**
     * OVERRIDE implementation from FilesystemCommonTrait since our directory structure differs from one provided
     * @see FilesystemCommonTrait::getFile()
     */
    protected function getFile(string $id, bool $mkdir = false): string
    {
        if ($mkdir) {
            $this->ensureDirectory();
        }
        return $this->directory.$this->name;
    }

    /**
     * OVERRIDE implementation from FilesystemCommonTrait since our directory structure differs from one provided
     * @see FilesystemCommonTrait::scanHashDir()
     */
    protected function scanHashDir(string $directory): Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (@scandir($directory, SCANDIR_SORT_NONE) ?: [] as $file) {
            if ('.' !== $file && '..' !== $file) {
                yield $directory . DIRECTORY_SEPARATOR . $file;
            }
        }
    }
}
