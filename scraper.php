<?php
/**
 * Scrapeer, a tiny PHP library that lets you scrape
 * HTTP(S) and UDP trackers for torrent information.
 *
 * This file is extensively based on Johannes Zinnau's
 * work, which can be found at https://goo.gl/7hyjde
 *
 * Licensed under a Creative Commons
 * Attribution-ShareAlike 3.0 Unported License
 * http://creativecommons.org/licenses/by-sa/3.0
 *
 * @package Scrapeer
 */

namespace Scrapeer;

/**
 * The one and only class you'll ever need.
 */
class Scraper {

	/**
	 * Current version of Scrapeer
	 *
	 * @var string
	 */
	const VERSION = '0.4.8';

	/**
	 * Array of errors
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Array of infohashes to scrape
	 *
	 * @var array
	 */
	private $infohashes = array();

	/**
	 * Initiates the scraper
	 *
	 * @throws \RangeException In case of invalid amount of info-hashes.
	 *
	 * @param array|string $hashes List (>1) or string of infohash(es).
	 * @param array|string $trackers List (>1) or string of tracker(s).
	 * @param int|null     $max_trackers Optional. Maximum number of trackers to be scraped, Default all.
	 * @param int          $timeout Optional. Maximum time for each tracker scrape in seconds, Default 2.
	 * @return array List of results.
	 */
	public function scrape( $hashes, $trackers, $max_trackers = null, $timeout = 2 ) {
		$final_result = array();

		if ( empty( $trackers ) ) {
			$this->errors[] = 'No tracker specified, aborting.';
			return $final_result;
		} else if ( ! is_array( $trackers ) ) {
			$trackers = array( $trackers );
		}

		try {
			$this->infohashes = $this->normalize_infohashes( $hashes );
		} catch ( \RangeException $e ) {
			$this->errors[] = $e->getMessage();
			return $final_result;
		}

		$max_iterations = isset( $max_trackers ) ? $max_trackers : count( $trackers );
		foreach ( $trackers as $index => $tracker ) {
			if ( ! empty( $this->infohashes ) && $index < $max_iterations ) {
				$tracker_info = parse_url( $tracker );
				$protocol = $tracker_info['scheme'];
				$host = $tracker_info['host'];
				if ( empty( $protocol ) || empty( $host ) ) {
					$this->errors[] = 'Skipping invalid tracker (' . $tracker . ').';
					continue;
				}

				$port = isset( $tracker_info['port'] ) ? $tracker_info['port'] : null;
				$path = isset( $tracker_info['path'] ) ? $tracker_info['path'] : null;
				$path_array = $path ? explode( '/', rtrim( $path, '/' ), 3 ) : array();
				$passkey = count( $path_array ) > 2  ? '/' . $path_array[1] : '';
				$result = $this->try_scrape( $protocol, $host, $port, $passkey, $timeout );
				$final_result = array_merge( $final_result, $result );
				continue;
			}
			break;
		}
		return $final_result;
	}

	/**
	 * Tries to scrape with a single tracker.
	 *
	 * @throws \Exception In case of unsupported protocol.
	 *
	 * @param string $protocol Protocol of the tracker.
	 * @param string $host Domain or address of the tracker.
	 * @param int    $port Optional. Port number of the tracker.
	 * @param string $passkey Optional. Passkey provided in the scrape request.
	 * @param int    $timeout Optional. Maximum time for each tracker scrape in seconds, Default 2.
	 * @return array List of results.
	 */
	private function try_scrape( $protocol, $host, $port, $passkey, $timeout ) {
		$infohashes = $this->infohashes;
		$this->infohashes = array();
		$results = array();
		try {
			switch ( $protocol ) {
				case 'udp':
					$port = isset( $port ) ? $port : 80;
					$results = $this->scrape_udp( $infohashes, $timeout, $host, $port );
					break;
				case 'http':
					$port = isset( $port ) ? $port : 80;
					$results = $this->scrape_http( $infohashes, $timeout, $protocol, $host, $port, $passkey );
					break;
				case 'https':
					$port = isset( $port ) ? $port : 443;
					$results = $this->scrape_http( $infohashes, $timeout, $protocol, $host, $port, $passkey );
					break;
				default:
					throw new \Exception( 'Unsupported protocol (' . $protocol . '://' . $host . ').' );
			}
		} catch ( \Exception $e ) {
			$this->infohashes = $infohashes;
			$this->errors[] = $e->getMessage();
		}
		return $results;
	}

	/**
	 * Normalizes the given hashes
	 *
	 * @throws \RangeException If amount of valid infohashes > 64 or < 1.
	 *
	 * @param array $infohashes List of infohash(es).
	 * @return array Normalized infohash(es).
	 */
	private function normalize_infohashes( $infohashes ) {
		if ( ! is_array( $infohashes ) ) {
			$infohashes = array( $infohashes );
		}

		foreach ( $infohashes as $index => $infohash ) {
			if ( ! preg_match( '#^[a-f0-9]{40}$#i', $infohash ) ) {
				$this->errors[] = 'Invalid infohash skipped (' . $infohash . ').';
				unset( $infohashes[ $index ] );
			}
		}

		$total_infohashes = count( $infohashes );
		if ( $total_infohashes > 64 || $total_infohashes < 1 ) {
			throw new \RangeException( 'Invalid amount of valid infohashes (' . $total_infohashes . ').' );
		}

		$infohashes = array_values( $infohashes );

		return $infohashes;
	}

	/**
	 * Initiates the UDP scraping
	 *
	 * @param array|string $infohashes List (>1) or string of infohash(es).
	 * @param int          $timeout Optional. Maximum time for each scrape in seconds, Default 2.
	 * @param string       $host Domain or IP address of the tracker.
	 * @param int          $port Optional. Port number of the tracker, Default 80.
	 * @return array List of results.
	 */
	private function scrape_udp( $infohashes, $timeout, $host, $port ) {
		$socket = $this->udp_create_connection( $timeout, $host, $port );
		$transaction_id = $this->udp_connection_request( $socket );
		$connection_id = $this->udp_connection_response( $socket, $transaction_id, $host, $port );
		$this->udp_scrape_request( $socket, $infohashes, $connection_id, $transaction_id );
		$results = $this->udp_scrape_response( $socket, $infohashes, $transaction_id, $host, $port );

		return $results;
	}

	/**
	 * Initiates the HTTP(S) scraping
	 *
	 * @param array|string $infohashes List (>1) or string of infohash(es).
	 * @param int          $timeout Optional. Maximum time for each scrape in seconds, Default 2.
	 * @param string       $protocol Protocol to use for the scraping.
	 * @param string       $host Domain or IP address of the tracker.
	 * @param int          $port Optional. Port number of the tracker, Default 80 (HTTP) or 443 (HTTPS).
	 * @param string       $passkey Optional. Passkey provided in the scrape request.
	 * @return array List of results.
	 */
	private function scrape_http( $infohashes, $timeout, $protocol, $host, $port, $passkey ) {
		$query = $this->http_query( $infohashes, $protocol, $host, $port, $passkey );
		$response = $this->http_response( $query, $timeout, $host, $port );
		$results = $this->http_data( $response, $infohashes, $host );

		return $results;
	}

	/**
	 * Builds the HTTP(S) query
	 *
	 * @param array|string $infohashes List (>1) or string of infohash(es).
	 * @param string       $protocol Protocol to use for the scraping.
	 * @param string       $host Domain or IP address of the tracker.
	 * @param int          $port Port number of the tracker, Default 80 (HTTP) or 443 (HTTPS).
	 * @param string       $passkey Optional. Passkey provided in the scrape request.
	 * @return string Request query.
	 */
	private function http_query( $infohashes, $protocol, $host, $port, $passkey ) {
		$tracker_url = $protocol . '://' . $host . ':' . $port . $passkey;
		$scrape_query = '';

		foreach ( $infohashes as $index => $infohash ) {
			if ( $index > 0 ) {
				$scrape_query .= '&info_hash=' . urlencode( pack( 'H*', $infohash ) );
			} else {
				$scrape_query .= '/scrape?info_hash=' . urlencode( pack( 'H*', $infohash ) );
			}
		}
		$request_query = $tracker_url . $scrape_query;

		return $request_query;
	}

	/**
	 * Executes the query and returns the result
	 *
	 * @throws \Exception If the connection can't be established.
	 * @throws \Exception If the response isn't valid.
	 *
	 * @param string $query The query that will be executed.
	 * @param int    $timeout Maximum time for each scrape in seconds, Default 2.
	 * @param string $host Domain or IP address of the tracker.
	 * @param int    $port Port number of the tracker, Default 80 (HTTP) or 443 (HTTPS).
	 * @return stream context resource Request response.
	 */
	private function http_response( $query, $timeout, $host, $port ) {
		$context = stream_context_create( array(
			'http' => array(
				'timeout' => $timeout,
			),
		));

		if ( false === ( $response = @file_get_contents( $query, false, $context ) ) ) {
			throw new \Exception( 'Invalid scrape connection (' . $host . ':' . $port . ').' );
		}

		if ( substr( $response, 0, 12 ) !== 'd5:filesd20:' ) {
			throw new \Exception( 'Invalid scrape response (' . $host . ':' . $port . ').' );
		}

		return $response;
	}

	/**
	 * Parses the response and returns the data
	 *
	 * @param stream context resource $response The response that will be parsed.
	 * @param array                   $infohashes List of infohash(es).
	 * @param string                  $host Domain or IP address of the tracker.
	 * @return array Parsed data.
	 */
	private function http_data( $response, $infohashes, $host ) {
		$torrents_data = array();

		foreach ( $infohashes as $infohash ) {
			$ben_hash = '/' . preg_quote( pack( 'H*', $infohash ), '/' );
			$search_string = $ben_hash . 'd(8:completei(\d+)e)?(10:downloadedi(\d+)e)?(10:incompletei(\d+)e)?/';
			preg_match( $search_string, $response, $matches );
			if ( ! empty( $matches ) ) {
				$torrent_info['seeders'] = $matches[2] ? intval( $matches[2] ) : 0;
				$torrent_info['completed'] = $matches[4] ? intval( $matches[4] ) : 0;
				$torrent_info['leechers'] = $matches[6] ? intval( $matches[6] ) : 0;
				$torrents_data[ $infohash ] = $torrent_info;
			} else {
				$this->collect_infohash( $infohash );
				$this->errors[] = 'Invalid infohash (' . $infohash . ') for tracker: ' . $host . '.';
			}
		}

		return $torrents_data;
	}

	/**
	 * Creates the UDP socket and establishes the connection
	 *
	 * @throws \Exception If the socket couldn't be created or connected to.
	 *
	 * @param int    $timeout Maximum time for each scrape in seconds, Default 2.
	 * @param string $host Domain or IP address of the tracker.
	 * @param int    $port Port number of the tracker, Default 80.
	 * @return socket resource Created and connected socket.
	 */
	private function udp_create_connection( $timeout, $host, $port ) {
		if ( false === ( $socket = @socket_create( AF_INET, SOCK_DGRAM, SOL_UDP ) ) ) {
			throw new \Exception( "Couldn't create socket." );
		}

		socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array( 'sec' => $timeout, 'usec' => 0 ) );
		socket_set_option( $socket, SOL_SOCKET, SO_SNDTIMEO, array( 'sec' => $timeout, 'usec' => 0 ) );
		if ( false === @socket_connect( $socket, $host, $port ) ) {
			throw new \Exception( "Couldn't connect to socket." );
		}

		return $socket;
	}

	/**
	 * Writes to the connected socket and returns the transaction ID
	 *
	 * @throws \Exception If the socket couldn't be written to.
	 *
	 * @param socket resource $socket The socket resource.
	 * @return int The transaction ID.
	 */
	private function udp_connection_request( $socket ) {
		$connection_id = "\x00\x00\x04\x17\x27\x10\x19\x80";
		$action = 0;
		$transaction_id = mt_rand( 0, 2147483647 );
		$buffer = $connection_id . pack( 'N', $action ) . pack( 'N', $transaction_id );
		if ( false === @socket_write( $socket, $buffer, strlen( $buffer ) ) ) {
			socket_close( $socket );
			throw new \Exception( "Couldn't write to socket." );
		}

		return $transaction_id;
	}

	/**
	 * Reads the connection response and returns the connection ID
	 *
	 * @throws \Exception If anything fails with the scraping.
	 *
	 * @param socket resource $socket The socket resource.
	 * @param int             $transaction_id The transaction ID.
	 * @param string          $host Domain or IP address of the tracker.
	 * @param int             $port Port number of the tracker, Default 80.
	 * @return string The connection ID.
	 */
	private function udp_connection_response( $socket, $transaction_id, $host, $port ) {
		if ( false === ( $response = @socket_read( $socket, 16 ) ) ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape connection (' . $host . ':' . $port . ').' );
		}

		if ( strlen( $response ) < 16 ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape response (' . $host . ':' . $port . ').' );
		}

		$result = unpack( 'Naction/Ntransaction_id', $response );
		if ( 0 !== $result['action'] || $result['transaction_id'] !== $transaction_id ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape result (' . $host . ':' . $port . ').' );
		}

		$connection_id = substr( $response, 8, 8 );

		return $connection_id;
	}

	/**
	 * Writes to the connected socket
	 *
	 * @throws \Exception If the socket couldn't be written to.
	 *
	 * @param socket resource $socket The socket resource.
	 * @param array           $hashes List (>1) or string of infohash(es).
	 * @param string          $connection_id The connection ID.
	 * @param int             $transaction_id The transaction ID.
	 */
	private function udp_scrape_request( $socket, $hashes, $connection_id, $transaction_id ) {
		$action = 2;
		$infohashes = '';

		foreach ( $hashes as $infohash ) {
			$infohashes .= pack( 'H*', $infohash );
		}

		$buffer = $connection_id . pack( 'N', $action ) . pack( 'N', $transaction_id ) . $infohashes;
		if ( false === @socket_write( $socket, $buffer, strlen( $buffer ) ) ) {
			socket_close( $socket );
			throw new \Exception( "Couldn't write to socket." );
		}
	}

	/**
	 * Reads the socket response and returns the torrent data
	 *
	 * @throws \Exception If anything fails while reading the response.
	 *
	 * @param socket resource $socket The socket resource.
	 * @param array           $hashes List (>1) or string of infohash(es).
	 * @param int             $transaction_id The transaction ID.
	 * @param string          $host Domain or IP address of the tracker.
	 * @param int             $port Port number of the tracker, Default 80.
	 * @return array Scraped torrent data.
	 */
	private function udp_scrape_response( $socket, $hashes, $transaction_id, $host, $port ) {
		$read_length = 8 + ( 12 * count( $hashes ) );

		if ( false === ( $response = @socket_read( $socket, $read_length ) ) ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape connection (' . $host . ':' . $port . ').' );
		}
		socket_close( $socket );

		if ( strlen( $response ) < $read_length ) {
			throw new \Exception( 'Invalid scrape response (' . $host . ':' . $port . ').' );
		}

		$result = unpack( 'Naction/Ntransaction_id', $response );
		if ( 2 !== $result['action'] || $result['transaction_id'] !== $transaction_id ) {
			throw new \Exception( 'Invalid scrape result (' . $host . ':' . $port . ').' );
		}

		$torrents_data = array();
		$index = 8;

		foreach ( $hashes as $infohash ) {
			$search_string = substr( $response, $index, 12 );
			$content = unpack( 'N', $search_string )[1];
			if ( ! empty( $content ) ) {
				$results = unpack( 'Nseeders/Ncompleted/Nleechers', $search_string );
				$torrents_data[ $infohash ] = $results;
			} else {
				$this->collect_infohash( $infohash );
				$this->errors[] = 'Invalid infohash (' . $infohash . ') for tracker: ' . $host . '.';
			}
			$index += 12;
		}

		return $torrents_data;
	}

	/**
	 * Collects info-hashes that couldn't be scraped.
	 *
	 * @param string $infohash Infohash that wasn't scraped.
	 */
	private function collect_infohash( $infohash ) {
		$this->infohashes[] = $infohash;
	}

	/**
	 * Checks if there are any errors
	 *
	 * @return boolean True or false, depending if errors are present or not.
	 */
	public function has_errors() {
		return ! empty( $this->errors );
	}

	/**
	 * Returns all the errors that were logged
	 *
	 * @return array All the logged errors.
	 */
	public function get_errors() {
		return $this->errors;
	}
}
