# Scrapeer

Essential PHP library that scrapes HTTP(S) and UDP trackers for torrent information.

![scrapeer image](https://i.imgur.com/HYGFBlg.jpg)

*Original image by [@alinnnaaaa](https://unsplash.com/photos/ZiQkhI7417A).*

# Features
- Retrieves seeders, leechers and completed torrent information
- Supports HTTP, HTTPS and UDP trackers
- Automatically discards invalid trackers and info-hashes
- Allows setting timeout per tracker and max. number of trackers
- Supports up to 64 info-hashes per scrape
- Aims to be as lightweight, straightforward and efficient as possible
- Supports scraping via scrape (default) and announce requests :sparkles:

# Note
This is the only official source for Scrapeer. Other repositories — e.g. the ones on Packagist — are unofficial and should be avoided!

# Basic usage examples
### Single info-hash and single tracker:
```php
require 'scraper.php';

$scraper = new Scrapeer\Scraper();

$tracker = array( 'udp://tracker.coppersurfer.tk:6969/announce' );
$hash = array( '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

$info = $scraper->scrape( $hash, $tracker );
print_r( $info );
```
```php 
Array ( ["4344503B7E797EBF31582327A5BAAE35B11BDA01"] => Array ( ["seeders"] => 88 ["completed"] => 7737 ["leechers"] => 6 ) )
```

- If not specified, the port will default to 80 for HTTP/UDP and to 443 for HTTPS.
- Single elements may also be strings instead of arrays.

### Single info-hash and multiple trackers (recommended usage):
```php
$trackers = array( 'http://www.opentrackr.org/announce', 'udp://tracker.coppersurfer.tk:6969/announce' );
$hash = array( '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

$info = $scraper->scrape( $hash, $trackers );
print_r( $info );
```
```php
Array ( ["4344503B7E797EBF31582327A5BAAE35B11BDA01"] => Array ( ["seeders"] => 59 ["completed"] => 83 ["leechers"] => 3 ) )
```

- First tracker in the array will be used, if it fails (invalid tracker, invalid info-hash or invalid info-hash for that tracker) the second tracker will be used and so on.
- In this case we get a valid result from the first tracker, notice that we get different information for the same torrent - this is to be expected, as different trackers may be more or less up-to-date than others.

### Multiple info-hashes and single tracker:
```php
$tracker = array( 'http://tracker.internetwarriors.net:1337/announce' );
$hashes = array( '699cda895af6fbd5a817fff4fe6fa8ab87e36f48', '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

$info = $scraper->scrape( $hashes, $tracker );
print_r( $info );
```
```php
Array ( ["699cda895af6fbd5a817fff4fe6fa8ab87e36f48"] => Array ( ["seeders"] => 4 ["completed"] => 236 ["leechers"] => 0 ) ["4344503B7E797EBF31582327A5BAAE35B11BDA01"] => Array ( ["seeders"] => 7 ["completed"] => 946 ["leechers"] => 3 ) )
```

- Info-hashes can be upper or lower case.

### Multiple info-hashes and multiple trackers:
```php
$trackers = array( 'udp://tracker.coppersurfer.tk:6969/announce', 'http://explodie.org:6969/announce' );
$hashes = array( '699cda895af6fbd5a817fff4fe6fa8ab87e36f48', '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

$info = $scraper->scrape( $hashes, $trackers );
print_r( $info );
```
```php
Array ( ["699cda895af6fbd5a817fff4fe6fa8ab87e36f48"] => Array ( ["seeders"] => 52 ["completed"] => 2509 ["leechers"] => 1 ) ["4344503B7E797EBF31582327A5BAAE35B11BDA01"] => Array ( ["seeders"] => 97 ["completed"] => 7751 ["leechers"] => 11 ) )
```

# Advanced usage examples
## Error logging
```php
$trackers = array( 'http://invalidtracker:6767/announce', 'udp://tracker.coppersurfer.tk:6969/announce' );
$hashes = array( '699cda895af6fbd5a817fff4fe6fa8ab87e36f48', '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

$info = $scraper->scrape( $hashes, $trackers );

print_r( $info );

// Check if we have any errors.
if ( $scraper->has_errors() ) {
	// Get the errors and print them.
	print_r( $scraper->get_errors() );
}
```
```php
Array ( ["699cda895af6fbd5a817fff4fe6fa8ab87e36f48"] => Array ( ["seeders"] => 49 ["completed"] => 2509 ["leechers"] => 1 ) ["4344503B7E797EBF31582327A5BAAE35B11BDA01"] => Array ( ["seeders"] => 99 ["completed"] => 7754 ["leechers"] => 7 ) ) Array ( [0] => "Invalid scrape connection (invalidtracker:6767)." )
```

- The first tracker is not valid, it will be skipped and an error will be added to the error logger.
- The scraper keeps scraping until one valid tracker is found or there are no more trackers to try.
- Invalid info-hashes will be logged and skipped.

## Advanced options
### Maximum trackers
```php
$trackers = array( 'http://invalidtracker:6767/announce', 'udp://tracker.coppersurfer.tk:6969/announce' );
$hashes = array( '699cda895af6fbd5a817fff4fe6fa8ab87e36f48', '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

// The max. amount of trackers to try is 1.
$info = $scraper->scrape( $hashes, $trackers, 1 );
```
- The scraper will stop after the first tracker, regardless of its validity.
- Default: All trackers in the array.

### Timeout per tracker
```php
$trackers = array( 'http://invalidtracker:6767/announce', 'udp://tracker.coppersurfer.tk:6969/announce' );
$hashes = array( '699cda895af6fbd5a817fff4fe6fa8ab87e36f48', '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

// The max. amount of trackers to try is 2 and timeout per tracker is 3s.
$info = $scraper->scrape( $hashes, $trackers, 2, 3 );
```
- Each tracker will have a timeout of 3 seconds (total timeout in this case would be 6 seconds).
- Default: 2 seconds (recommended).

### Announce scraping
```php
$trackers = array( 'udp://tracker.opentrackr.org:1337/announce', 'http://explodie.org:6969/announce' );
$hashes = array( '699cda895af6fbd5a817fff4fe6fa8ab87e36f48', '4344503B7E797EBF31582327A5BAAE35B11BDA01' );

$info = $scraper->scrape( $hashes, $trackers, 2, 3, true );
```
- Default: false.
- Prefer scrape over announce requests for multiple info-hashes (usually faster).
- _Note:_ UDP trackers only return seeders and leechers information. This is a [protocol limitation](http://www.bittorrent.org/beps/bep_0015.html).

# FAQs
- What are info-hashes? How do I get them?

From [The BitTorrent Protocol Specification](http://www.bittorrent.org/beps/bep_0003.html):

> The 20 byte sha1 hash of the bencoded form of the info value from the metainfo file. Note that this is a substring of the metainfo file. The info-hash must be the hash of the encoded form as found in the .torrent file, regardless of it being invalid. This value will almost certainly have to be escaped.

There are many ways to retrieve the info-hashes from your torrents, a PHP based solution would be [torrent-bencode](https://github.com/bhutanio/torrent-bencode) for example.

- I need public trackers! Where can I find some?

Check out [newTrackon](https://newtrackon.com/) and [trackerslist](https://github.com/ngosang/trackerslist/blob/master/trackers_all.txt).
