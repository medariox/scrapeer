# Scrapeer
Scrapeer, a tiny PHP library that lets you scrape HTTP(S) and UDP trackers for torrent information.

# Features
- Retrieves seeders, leechers and completed torrent information
- Supports HTTP, HTTPS and UDP trackers
- Automatically discards invalid trackers and infohashes
- Allows setting timeout per tracker and max. number of trackers
- Supports up to 64 infohashes per scrape
- Aims to be as lightweight and efficient as possible

# Basic usage examples
Single infohash and single tracker (UDP):
```
require 'scraper.php';

$scraper = new Scrapeer\Scraper();

$tracker = 'udp://tracker.coppersurfer.tk:6969/announce';
$hash = '4344503B7E797EBF31582327A5BAAE35B11BDA01';

$info = $scraper->scrape( $hash, $tracker );
print_r($info);
```
```Array ( [4344503B7E797EBF31582327A5BAAE35B11BDA01] => Array ( [seeders] => 88 [completed] => 77 [leechers] => 6 ) )```
