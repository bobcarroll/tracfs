<?php

/**
 * TracFS - a FUSE module for the Trac source browser
 * Copyright (c) 2008 Bob Carroll
 * 
 * This software is free software; you can redistribute it
 * and/or modify it under the terms of the GNU General Public 
 * License as published by the Free Software Foundation; 
 * either version 2, or (at your option) any later version.
 * 
 * This software is distributed in the hope that it will be 
 * useful, but WITHOUT ANY WARRANTY; without even the implied 
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR 
 * PURPOSE.  See the GNU General Public License for more 
 * details.
 * 
 * You should have received a copy of the GNU General Public 
 * License along with this software; if not, write to the 
 * Free Software Foundation, Inc., 51 Franklin St, Fifth Floor, 
 * Boston, MA  02110-1301 USA
 */

/**
 * tracfs.php
 *
 * @author Bob Carroll (bob.carroll@alum.rit.edu)
 */

if (!extension_loaded("fuse"))
	dl("fuse." . PHP_SHLIB_SUFFIX);

class TracFS extends FuseWrapper
{
	// Trac base URL
	private $m_url;

	// Options table
	private $m_htopts = array();

	// Directory and metadata caches
	private $m_dir_cache = array();
	private $m_meta_cache = array();

	/**
	 * [Constructor]
	 */
	public function __construct($url, $opts)
	{
		if (substr($url, strlen($url) - 1, 1) != "/")
			$url .= "/";
		$this->m_url = $url . "browser";
		$this->ParseOpts($opts);

		if ($this->m_htopts["doauth"] == "shib")
			$this->DoShibAuth();

		$hc = $this->InitCurl($this->m_url);
		curl_setopt($hc, CURLOPT_FOLLOWLOCATION, TRUE);
		$html = curl_exec($hc);
		if ($html === FALSE)
			throw new Exception("cURL Error: " . curl_error($hc));
		curl_close($hc);

		if (preg_match("/401 Authorization Required/", $html) > 0)
			throw new Exception("Authorization required!");
	}

	/**
	 * DoShibAuth()
	 */
	private function DoShibAuth()
	{
		printf("Negotiating Shibboleth session...\n");

		$hc = $this->InitCurl($this->m_url);
		curl_setopt(hc, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($hc, CURLOPT_UNRESTRICTED_AUTH, TRUE);
		curl_setopt( 
			$hc, 
			CURLOPT_USERPWD, 
			$this->m_htopts["username"] . ":" . $this->m_htopts["password"]);

		$html = curl_exec($hc);
		if ($html === FALSE)
			throw new Exception("cURL Error: " . curl_error($hc));
		curl_close($hc);

		if (preg_match("/401 Authorization Required/", $html) > 0)
			throw new Exception("Bad credentials!");

		if (preg_match("/SAMLResponse/", $html) == 1) {
			preg_match("/action=\"(.*)\" method/", $html, $action);
			$data = $this->GetFormFields($html);

			$hc = $this->InitCurl($action[1]);

			curl_setopt($hc, CURLOPT_POST, TRUE);
			curl_setopt($hc, CURLOPT_POSTFIELDS, $data);

			curl_exec($hc);
			curl_close($hc);
		}
	}

	/**
	 * FsGetAttr()
	 */
	public function FsGetAttr($path, $stbuf)
	{
		if (strcmp($path, "/") == 0) {
			$stbuf->st_mode = FUSE_S_IFDIR | 0555; 
			$stbuf->st_nlink = 1;

			return -FUSE_ENOERR;
		}

		if (!isset($this->m_meta_cache[$path])) {
			$parent = substr($path, 0, strrpos($path, "/") + 1);
			$this->ReadDirectory($parent);

			if (!isset($this->m_meta_cache[$path]))
				return -FUSE_ENOENT;
		}

		$stcache = $this->m_meta_cache[$path];

		$stbuf->st_mode = $stcache->st_mode;
		$stbuf->st_nlink = $stcache->st_nlink;
		$stbuf->st_size = $stcache->st_size;
		$stbuf->st_mtime = $stcache->st_mtime;
		$stbuf->st_ctime = $stcache->st_ctime;

		return -FUSE_ENOERR;
	}

	/**
	 * FsOpen()
	 */
	public function FsOpen($path, $fi)
	{
		if (!isset($this->m_meta_cache[$path]))
			return -FUSE_ENOFILE;

		if (($fi->flags & FUSE_O_ACCMODE) != FUSE_O_RDONLY)
			return -FUSE_ENOACCES;

		return -FUSE_ENOERR;
	}

	/**
	 * FsRead()
	 */
	public function FsRead($path, &$buf, $size, $offset, $fi)
	{
		$stat = $this->m_meta_cache[$path];
		$ph = "/tmp/" . sha1($path);

		if (file_exists($ph) && filesize($ph) == $stat->st_size) {
			$hf = fopen($ph, "r");
			fseek($hf, $offset, SEEK_SET);
			$buf = fread($hf, $size);
			fclose($hf);

			return strlen($buf);
		}
		
		$hc = $this->InitCurl($this->m_url . $path . "?format=raw");
		curl_setopt($hc, CURLOPT_BINARYTRANSFER, TRUE);
		$output = curl_exec($hc);
		curl_close($hc);

		if ($size < $stat->st_size) {
			$hf = fopen($ph, "w");
			fwrite($hf, $output);
			fclose($hf);
		}

		if ($offset > strlen($output))
			return 0;
		$buf = substr($output, $offset, $size);

		return strlen($output);
	}

	/**
	 * FsReadDir()
	 */
	public function FsReadDir($path, &$buf)
	{
		$buf[] = ".";
		$buf[] = "..";

		try {
			$files = $this->ReadDirectory($path);
		} catch (Exception $e) {
			return -FUSE_ENOTDIR;
		}

		foreach ($files as $f)
			$buf[] = $f;

		return -FUSE_ENOERR;
	}

	/**
	 * FsReadLink()
	 */
	public function FsReadLink($path, &$buf, $size)
	{
		$hc = $this->InitCurl($this->m_url . $path . "?format=raw");
		$output = curl_exec($hc);
		curl_close($hc);

		preg_match("/^link (.*)$/", $output, $matches);
		$buf = substr($matches[1], 0, $size);

		return -FUSE_NOERR;
	}

	/**
	 * GetFormFields()
	 */
	private function GetFormFields($html)
	{
		preg_match_all("/name=\"(.*)\" value=\"(.*)\"/", $html, $fields);

		$output = "";
		for ($i = 0; $i < sizeof($fields[1]); $i++) {
			if( $output != "" ) $output .= "&";
			$output .= $fields[1][$i] . "=" . urlencode($fields[2][$i]);
		}

		return $output;
	}

	/**
 	 * InitCurl()
	 */
	private function InitCurl($url = NULL)
	{
		$hc = curl_init($url);

		curl_setopt($hc, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($hc, CURLOPT_COOKIEJAR, "/tmp/tracfs_cookies");
		curl_setopt($hc, CURLOPT_COOKIEFILE, "/tmp/tracfs_cookies");

		return $hc;
	}

	/**
	 * ParseOpts()
	 */
	private function ParseOpts($csvopts)
	{
		$htopts = explode(",", $csvopts);
		foreach ($htopts as $o) {
			$kv = explode("=", $o);
			if (sizeof($kv) != 2) continue;

			$this->m_htopts[$kv[0]] = $kv[1];
		}
	}

	/**
	 * ReadDirectory()
	 */
	private function ReadDirectory($relpath)
	{
		if (substr($relpath, strlen($relpath) - 1, 1) != "/")
			$relpath .= "/";

		if (isset($this->m_dir_cache[$relpath]))
			return $this->m_dir_cache[$relpath];
		$results = array();

		$hc = $this->InitCurl($this->m_url . $relpath);
		$html = curl_exec($hc);
		curl_close($hc);

		$doc = new DOMDocument();
		$doc->loadHTML($html);
		$xp = new DOMXPath($doc);

		$xpdirs = $xp->query("//table[@id='dirlist']");
		if ($xpdirs->length == 0)
			throw new Exception("Specified path is not a directory!");
		$dircache = array();

		$xprows = $xp->query("tbody/tr", $xpdirs->item(0));
		for ($i = 0; $i < $xprows->length; $i++) {
			$xpcur = $xprows->item($i);

			$xpname = $xp->query("td[contains(@class, 'name')]/a", $xpcur);
			if ($xpname->length == 0)
				throw new Exception("Couldn't find file name!");
			$fname = $xpname->item(0)->textContent;

			if ($fname == "../")
				continue;

			$isdir = ($xpname->item(0)->getAttribute("class") == "dir");
			$dircache[] = $fname;

			$xpsize = $xp->query("td[contains(@class, 'size')]/span", $xpcur);
			if ($xpsize->length == 0)
				throw new Exception("Couldn't find file size!");
			$fsize = intval($xpsize->item(0)->getAttribute("title"));

			$xpage = $xp->query("td[contains(@class, 'age')]/a", $xpcur);
			if ($xpage->length == 0)
				throw new Exception("Couldn't find file age!");
			preg_match(
				"/timeline\?from=([^&]+)/s", 
				$xpage->item(0)->getAttribute("href"), 
				$matches);

			$stbuf = new FuseStat();
			$stbuf->st_mode = $isdir ?
				FUSE_S_IFDIR | 0555 :
				FUSE_S_IFREG | 0444;
			$stbuf->st_size = $fsize;
			$stbuf->st_nlink = 1;
			$stbuf->st_mtime = strtotime(urldecode($matches[1]));
			$stbuf->st_ctime = $stbuf->st_mtime;

			if (!$isdir && $stbuf->st_size > 0 && $stbuf->st_size < 100) {
				$hc = $this->InitCurl($this->m_url . $relpath . $matches[2][$i] . "?format=raw");
				$output = curl_exec($hc);
				curl_close($hc);

				if (preg_match("/^link (.*)$/", $output) == 1)
					$stbuf->st_mode = FUSE_S_IFLNK | 0777;
			}

			$this->m_meta_cache[$relpath . $fname] = $stbuf;
			$results[] = $fname;
		}

		$this->m_dir_cache[$relpath] = $dircache;
		return $results;
	}
}


if (sizeof($argv) == 2 && preg_match("/-V|--version/", $argv[1]) == 1) {
	TracFS::PrintVersion();
	exit(0);
}

if (sizeof($argv) < 3) {
	fprintf(STDERR,
		"usage: php tracfs.php <url> <mount-point> [options]\n" .
		"options:\n" .
		"	-V  --version     print FUSE version\n" .
		"	-d                enable debug output\n" .
		"	-v                verbose mode\n" .
		"	-o                mount options in the form key=value[,name=value,...]\n" .
 		"\n" .
		"mount options:\n" .
		"	doauth            authentication mechanism\n" .
		"	username          mount user name\n" .
		"	password          mount user password\n" .
		"\n" .
		"authentication mechanisms:\n" .
		"	shib              Shibboleth SSO\n");
	exit(1);
}

$fuse_args = array("php", $argv[2], "-r");
for ($i = 3; $i < sizeof($argv); $i++) {
	if ($argv[$i] == "-o" && $i + 1 < sizeof($argv))
		$opts = $argv[$i + 1];
	if ($argv[$i] == "-d" || $argv[$i] == "-v")
		$fuse_args[] = $argv[$i];
}

try {
	$fs = new TracFS($argv[1], $opts);
	$fs->Init($fuse_args);
} catch (Exception $e) {
	echo $e->getMessage() . "\n";
}

?>
