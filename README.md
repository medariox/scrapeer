# Scrapeer
Scrapeer, a tiny PHP library that lets you scrape HTTP(S) and UDP trackers for torrent information.

# Features
- Retrieves seeders, leechers and completed torrent information
- Supports HTTP, HTTPS and UDP trackers
- Automatically discards invalid trackers and info-hashes
- Allows setting timeout per tracker and max. number of trackers
- Supports up to 64 info-hashes per scrape
- Aims to be as lightweight and efficient as possible

# Basic usage examples
Single info-hash and single tracker (UDP):
```
require 'scraper.php';

$scraper = new Scrapeer\Scraper();

$tracker = array( 'udp://tracker.coppersurfer.tk:6969/announce' );
$hash = array( '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

$info = $scraper->scrape( $hash, $tracker );
print_r($info);
```
```Array ( [4344503B7E797EBF31582327A5BAAE35B11BDA01] => Array ( [seeders] => 88 [completed] => 7737 [leechers] => 6 ) )```

- If not specified, ports will default to 80 for HTTP/UDP and to 443 for HTTPS.
- Single elements may also be strings instead of arrays.

Single info-hash and multiple trackers (recommended usage):
```
$trackers = array( 'http://www.opentrackr.org/announce', 'udp://tracker.coppersurfer.tk:6969/announce' );
$hash = array( '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

$info = $scraper->scrape( $hash, $trackers );
print_r($info);
```
```Array ( [4344503B7E797EBF31582327A5BAAE35B11BDA01] => Array ( [seeders] => 59 [completed] => 83 [leechers] => 3 ) )```

- First tracker in the array will be used, if it fails (invalid tracker, invalid info-hash or invalid info-hash for that tracker) the second tracker will be used and so on.
