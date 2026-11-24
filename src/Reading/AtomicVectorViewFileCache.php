<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Native HTMLFileCache with atomic publication only for an explicitly forced
 * replacement of an existing view file.
 *
 * MediaWiki 1.43's FileCacheBase::saveText() writes directly to its existing
 * pathname. A background migration of thousands of valid HTML files must not
 * expose an in-progress/truncated write to anonymous readers. The native
 * HTMLFileCache::saveToFileCache() continues to produce the same metadata,
 * compression and filename; only the final file publication is atomic.
 */
final class AtomicVectorViewFileCache extends \HTMLFileCache {
	private bool $published = false;

	public function wasPublished(): bool {
		return $this->published;
	}

	public function saveText($text) {
		$this->published = false;
		if ($this->mType !== 'view') {
			throw new \LogicException('Atomic Vector publication is restricted to action=view');
		}
		$payload = $this->useGzip() ? gzencode($text) : $text;
		if (!is_string($payload) || strlen($payload) < 512) {
			return false;
		}
		$this->checkCacheDirs();
		$target = $this->cachePath();
		if (is_link($target)) {
			return false;
		}
		$directory = dirname($target);
		$temp = @tempnam($directory, '.wikidebates-view-');
		if ($temp === false || dirname($temp) !== $directory) {
			return false;
		}
		try {
			$mode = is_file($target) ? (fileperms($target) & 0777) : 0644;
			if (!@chmod($temp, $mode) ||
				@file_put_contents($temp, $payload, LOCK_EX) !== strlen($payload) ||
				!@rename($temp, $target)) {
				return false;
			}
			$this->mCached = true;
			$this->published = true;
			return $payload;
		} finally {
			if (is_file($temp)) {
				@unlink($temp);
			}
		}
	}
}
