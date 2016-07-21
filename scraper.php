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
	const VERSION = '0.1';

	/**
	 * Array of errors
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Initiates the scraper
	 *
	 * @throws \Exception In case of unsupported protocol.
	 *
	 * @param array|string $hashes List (>1) or string of infohash(es).
	 * @param array|string $trackers List (>1) or string of tracker(s).
	 * @param int|null     $max_trackers Optional. Maximum number of trackers to be scraped, Default all.
	 * @param int          $timeout Optional. Maximum time for each tracker scrape in seconds, Default 2.
	 * @return array List of results.
	 */
	public function scrape( $hashes, $trackers, $max_trackers = null, $timeout = 2 ) {
		if ( empty( $trackers ) ) {
			$this->errors[] = 'No tracker specified, aborting.';
			return;
		} else if ( ! is_array( $trackers ) ) {
			$trackers = array( $trackers );
		}

		try {
			$infohashes = $this->normalize_infohashes( $hashes );
		} catch ( \RangeException $e ) {
			$this->errors[] = $e->getMessage();
			return;
		}

		$max_iterations = isset( $max_trackers ) ? $max_trackers : count( $trackers );
		foreach ( $trackers as $index => $tracker ) {
			if ( $index < $max_iterations ) {
				$tracker_info = parse_url( $tracker );
				$protocol = $tracker_info['scheme'];
				$host = $tracker_info['host'];
				$results = '';
				try {
					if ( $protocol === 'udp' ) {
						$port = isset( $tracker_info['port'] ) ? $tracker_info['port'] : 80;
						$results = $this->scrape_udp( $infohashes, $timeout, $host, $port );
					} else if ( $protocol === 'http' ) {
						$port = isset( $tracker_info['port'] ) ? $tracker_info['port'] : 80;
						$results = $this->scrape_http( $infohashes, $timeout, $protocol, $host, $port );
					} else if ( $protocol === 'https' ) {
						$port = isset( $tracker_info['port'] ) ? $tracker_info['port'] : 443;
						$results = $this->scrape_http( $infohashes, $timeout, $protocol, $host, $port );
					} else {
						throw new \Exception( 'Unsupported protocol (' . $protocol . '://' . $host . ').' );
					}
				} catch ( \Exception $e ) {
					$this->errors[] = $e->getMessage();
					continue;
				}
				return $results;
			}
		}
	}

	/**
	 * Normlizes the given hashes
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
	private function scrape_udp( $infohashes, $timeout, string $host, $port ) {
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
	 * @return array List of results.
	 */
	private function scrape_http( $infohashes, $timeout, string $protocol, string $host, $port ) {
		$query = $this->http_query( $infohashes, $protocol, $host, $port );
		$response = $this->http_response( $query, $timeout, $host, $port );
		$results = $this->http_data( $response, $infohashes, $host );

		return $results;
	}

	/**
	 * Builds the HTTP(S) query
	 *
	 * @param array|string $infohashes List (>1) or string of infohash(es).
	 * @param string 	   $protocol Protocol to use for the scraping.
	 * @param string 	   $host Domain or IP address of the tracker.
	 * @param int 	       $port Port number of the tracker, Default 80 (HTTP) or 443 (HTTPS).
	 * @return string Request query.
	 */
	private function http_query( $infohashes, string $protocol, string $host, $port ) {
		$tracker_url = $protocol . '://' . $host . ':' . $port;
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
	private function http_response( string $query, $timeout, string $host, $port ) {
		$context = stream_context_create( array(
			'http' => array(
				'timeout' => $timeout,
			),
		));

		if ( ! $response = file_get_contents( $query, false, $context ) ) {
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
	private function http_data( $response, $infohashes, string $host ) {
		$torrents_data = array();

		foreach ( $infohashes as $infohash ) {
			$ben_hash = '/' . pack( 'H*', $infohash );
			$search_string = $ben_hash . 'd8:completei(\d+)e10:downloadedi(\d+)e10:incompletei(\d+)ee/';
			preg_match( $search_string , $response, $match );
			if ( ! empty( $match ) ) {
				$torrent_info['seeders'] = $match[1];
				$torrent_info['completed'] = $match[2];
				$torrent_info['leechers'] = $match[3];
				$torrents_data[ $infohash ] = $torrent_info;
			} else {
				$this->errors[] = 'Invalid infohash (' . $infohash . ') for tracker: ' . $host . '.';
			}
		}

		return $torrents_data;
	}

	/**
	 * Creates the UDP socket and establishes the connection
	 *
	 * @throws \Exception If the socket couldn't be created.
	 *
	 * @param int    $timeout Maximum time for each scrape in seconds, Default 2.
	 * @param string $host Domain or IP address of the tracker.
	 * @param int    $port Port number of the tracker, Default 80.
	 * @return socket resource Created and connected socket.
	 */
	private function udp_create_connection( $timeout, string $host, $port ) {
		$socket = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );

		if ( ! is_resource( $socket ) ) {
			throw new \Exception( "Couldn't create socket." );
		}

		socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array( 'sec' => $timeout, 'usec' => 0 ) );
		socket_set_option( $socket, SOL_SOCKET, SO_SNDTIMEO, array( 'sec' => $timeout, 'usec' => 0 ) );
		socket_connect( $socket, $host, $port );

		return $socket;
	}

	/**
	 * Writes to the connected socket and returns the transaction ID
	 *
	 * @param socket resource $socket The socket resource.
	 * @return string The transaction ID.
	 */
	private function udp_connection_request( $socket ) {
		$connection_id = "\x00\x00\x04\x17\x27\x10\x19\x80";
		$action = 0;
		$transaction_id = random_int( 0, 255 );
		$buffer = $connection_id . pack( 'N', $action ) . pack( 'N', $transaction_id );
		socket_write( $socket, $buffer, strlen( $buffer ) );

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
	private function udp_connection_response( $socket, $transaction_id, string $host, $port ) {
		if ( ! $response = socket_read( $socket, 16 ) ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape connection (' . $host . ':' . $port . ').' );
		}

		if ( strlen( $response ) < 16 ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape response (' . $host . ':' . $port . ').' );
		}

		$result = unpack( 'Naction/Ntransaction_id', $response );
		if ( $result['action'] !== 0 || $result['transaction_id'] !== $transaction_id ) {
			throw new \Exception( 'Invalid scrape result (' . $host . ':' . $port . ').' );
		}
		$connection_id = substr( $response, 8, 8 );

		return $connection_id;
	}

	/**
	 * Writes to the connected socket
	 *
	 * @param socket resource $socket The socket resource.
	 * @param array           $hashes List (>1) or string of infohash(es).
	 * @param string          $connection_id The connection ID.
	 * @param int             $transaction_id The transaction ID.
	 */
	private function udp_scrape_request( $socket, $hashes, string $connection_id, $transaction_id ) {
		$action = 2;
		$infohashes = '';

		foreach ( $hashes as $infohash ) {
			$infohashes .= pack( 'H*', $infohash );
		}

		$buffer = $connection_id . pack( 'N', $action ) . pack( 'N', $transaction_id ) . $infohashes;
		socket_write( $socket, $buffer, strlen( $buffer ) );
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
	private function udp_scrape_response( $socket, $hashes, $transaction_id, string $host, $port ) {
		$read_length = 8 + ( 12 * count( $hashes ) );

		if ( ! $response = socket_read( $socket, $read_length ) ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape connection (' . $host . ':' . $port . ').' );
		}

		if ( strlen( $response ) < $read_length ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape response (' . $host . ':' . $port . ').' );
		}

		$result = unpack( 'Naction/Ntransaction_id', $response );
		if ( $result['action'] !== 2 || $result['transaction_id'] !== $transaction_id ) {
			socket_close( $socket );
			throw new \Exception( 'Invalid scrape result (' . $host . ':' . $port . ').' );
		}
		socket_close( $socket );

		$torrents_data = array();
		$index = 8;

		foreach ( $hashes as $infohash ) {
			$search_string = substr( $response, $index, 12 );
			$content = unpack( 'N', $search_string )[1];
			if ( ! empty( $content ) ) {
				$results = unpack( 'Nseeders/Ncompleted/Nleechers', $search_string );
				$torrents_data[ $infohash ] = $results;
			} else {
				$this->errors[] = 'Invalid infohash (' . $infohash . ') for tracker: ' . $host . '.';
			}
			$index += 12;
		}

		return $torrents_data;
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
