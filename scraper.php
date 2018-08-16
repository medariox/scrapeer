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
    const VERSION = '0.5.4';

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
     * Timeout for a single tracker
     *
     * @var int
     */
    private $timeout;

    /**
     * Initiates the scraper
     *
     * @throws \RangeException In case of invalid amount of info-hashes.
     *
     * @param array|string $hashes List (>1) or string of infohash(es).
     * @param array|string $trackers List (>1) or string of tracker(s).
     * @param int|null     $max_trackers Optional. Maximum number of trackers to be scraped, Default all.
     * @param int          $timeout Optional. Maximum time for each tracker scrape in seconds, Default 2.
     * @param bool         $announce Optional. Use announce instead of scrape, Default false.
     * @return array List of results.
     */
    public function scrape( $hashes, $trackers, $max_trackers = null, $timeout = 2, $announce = false ) {
        $final_result = array();

        if ( empty( $trackers ) ) {
            $this->errors[] = 'No tracker specified, aborting.';
            return $final_result;
        } else if ( ! is_array( $trackers ) ) {
            $trackers = array( $trackers );
        }

        if ( is_int( $timeout ) ) {
            $this->timeout = $timeout;
        } else {
            $this->timeout = 2;
            $this->errors[] = 'Timeout must be an integer. Using default value.';
        }

        try {
            $this->infohashes = $this->normalize_infohashes( $hashes );
        } catch ( \RangeException $e ) {
            $this->errors[] = $e->getMessage();
            return $final_result;
        }

        $max_iterations = is_int( $max_trackers ) ? $max_trackers : count( $trackers );
        foreach ( $trackers as $index => $tracker ) {
            if ( ! empty( $this->infohashes ) && $index < $max_iterations ) {
                $info = parse_url( $tracker );
                $protocol = $info['scheme'];
                $host = $info['host'];
                if ( empty( $protocol ) || empty( $host ) ) {
                    $this->errors[] = 'Skipping invalid tracker (' . $tracker . ').';
                    continue;
                }

                $port = isset( $info['port'] ) ? $info['port'] : null;
                $path = isset( $info['path'] ) ? $info['path'] : null;
                $passkey = $this->get_passkey( $path );
                $result = $this->try_scrape( $protocol, $host, $port, $passkey, $announce );
                $final_result = array_merge( $final_result, $result );
                continue;
            }
            break;
        }
        return $final_result;
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
            if ( ! preg_match( '/^[a-f0-9]{40}$/i', $infohash ) ) {
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
     * Returns the passkey found in the scrape request.
     *
     * @param string $path Path from the scrape request.
     * @return string Passkey or empty string.
     */
    private function get_passkey( $path ) {
        if ( ! is_null( $path ) && preg_match( '/[a-z0-9]{32}/i', $path, $matches ) ) {
            return '/' . $matches[0];
        }

        return '';
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
     * @param bool   $announce Optional. Use announce instead of scrape, Default false.
     * @return array List of results.
     */
    private function try_scrape( $protocol, $host, $port, $passkey, $announce ) {
        $infohashes = $this->infohashes;
        $this->infohashes = array();
        $results = array();
        try {
            switch ( $protocol ) {
                case 'udp':
                    $port = isset( $port ) ? $port : 80;
                    $results = $this->scrape_udp( $infohashes, $host, $port, $announce );
                    break;
                case 'http':
                    $port = isset( $port ) ? $port : 80;
                    $results = $this->scrape_http( $infohashes, $protocol, $host, $port, $passkey, $announce );
                    break;
                case 'https':
                    $port = isset( $port ) ? $port : 443;
                    $results = $this->scrape_http( $infohashes, $protocol, $host, $port, $passkey, $announce );
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
     * Initiates the HTTP(S) scraping
     *
     * @param array|string $infohashes List (>1) or string of infohash(es).
     * @param string       $protocol Protocol to use for the scraping.
     * @param string       $host Domain or IP address of the tracker.
     * @param int          $port Optional. Port number of the tracker, Default 80 (HTTP) or 443 (HTTPS).
     * @param string       $passkey Optional. Passkey provided in the scrape request.
     * @param bool         $announce Optional. Use announce instead of scrape, Default false.
     * @return array List of results.
     */
    private function scrape_http( $infohashes, $protocol, $host, $port, $passkey, $announce ) {
        if ( true === $announce ) {
            $response = $this->http_announce( $infohashes, $protocol, $host, $port, $passkey );
        } else {
            $query = $this->http_query( $infohashes, $protocol, $host, $port, $passkey );
            $response = $this->http_request( $query, $host, $port );
        }
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
     * @param string $host Domain or IP address of the tracker.
     * @param int    $port Port number of the tracker, Default 80 (HTTP) or 443 (HTTPS).
     * @return string Request response.
     */
    private function http_request( $query, $host, $port ) {
        $context = stream_context_create( array(
            'http' => array(
                'timeout' => $this->timeout,
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
     * Builds the query, sends the announce request and returns the data
     *
     * @throws \Exception If the connection can't be established.
     *
     * @param array|string $infohashes List (>1) or string of infohash(es).
     * @param string       $protocol Protocol to use for the scraping.
     * @param string       $host Domain or IP address of the tracker.
     * @param int          $port Port number of the tracker, Default 80 (HTTP) or 443 (HTTPS).
     * @param string       $passkey Optional. Passkey provided in the scrape request.
     * @return string Request response.
     */
    private function http_announce( $infohashes, $protocol, $host, $port, $passkey ) {
        $tracker_url = $protocol . '://' . $host . ':' . $port . $passkey;
        $context = stream_context_create( array(
            'http' => array(
                'timeout' => $this->timeout,
            ),
        ));

        $response_data = '';
        foreach ( $infohashes as $infohash ) {
            $query = $tracker_url . '/announce?info_hash=' . urlencode( pack( 'H*', $infohash ) );
            if ( false === ( $response = @file_get_contents( $query, false, $context ) ) ) {
                throw new \Exception( 'Invalid announce connection (' . $host . ':' . $port . ').' );
            }

            if ( substr( $response, 0, 12 ) !== 'd8:completei' ||
                substr( $response, 0, 46 ) === 'd8:completei0e10:downloadedi0e10:incompletei1e' ) {
                continue;
            }

            $ben_hash = '20:' . pack( 'H*', $infohash ) . 'd';
            $response_data .= $ben_hash . $response;
        }

        return $response_data;
    }

    /**
     * Parses the response and returns the data
     *
     * @param string $response The response that will be parsed.
     * @param array  $infohashes List of infohash(es).
     * @param string $host Domain or IP address of the tracker.
     * @return array Parsed data.
     */
    private function http_data( $response, $infohashes, $host ) {
        $torrents_data = array();

        foreach ( $infohashes as $infohash ) {
            $ben_hash = '20:' . pack( 'H*', $infohash ) . 'd';
            $start_pos = strpos( $response, $ben_hash );
            if ( false !== $start_pos ) {
                $start = $start_pos + 24;
                $head = substr( $response, $start );
                $end = strpos( $head, 'ee' ) + 1;
                $data = substr( $response, $start, $end );

                $seeders = '8:completei';
                $torrent_info['seeders'] = $this->get_information( $data, $seeders, 'e' );

                $completed = '10:downloadedi';
                $torrent_info['completed'] = $this->get_information( $data, $completed, 'e' );

                $leechers = '10:incompletei';
                $torrent_info['leechers'] = $this->get_information( $data, $leechers, 'e' );

                $torrents_data[ $infohash ] = $torrent_info;
            } else {
                $this->collect_infohash( $infohash );
                $this->errors[] = 'Invalid infohash (' . $infohash . ') for tracker: ' . $host . '.';
            }
        }

        return $torrents_data;
    }

    /**
     * Parses a string and returns the data between $start and $end.
     *
     * @param string $data The data that will be parsed.
     * @param string $start Beginning part of the data.
     * @param string $end Ending part of the data.
     * @return int Parsed information or 0.
     */
    private function get_information( $data, $start, $end ) {
        $start_pos = strpos( $data, $start );
        if ( false !== $start_pos ) {
            $start = $start_pos + strlen( $start );
            $head = substr( $data, $start );
            $end = strpos( $head, $end );
            $information = substr( $data, $start, $end );

            return (int) $information;
        }

        return 0;
    }

    /**
     * Initiates the UDP scraping
     *
     * @param array|string $infohashes List (>1) or string of infohash(es).
     * @param string       $host Domain or IP address of the tracker.
     * @param int          $port Optional. Port number of the tracker, Default 80.
     * @param bool         $announce Optional. Use announce instead of scrape, Default false.
     * @return array List of results.
     */
    private function scrape_udp( $infohashes, $host, $port, $announce ) {
        list( $socket, $transaction_id, $connection_id ) = $this->prepare_udp( $host, $port );

        if ( true === $announce ) {
            $response = $this->udp_announce( $socket, $infohashes, $connection_id );
            $keys = 'Nleechers/Nseeders';
            $start = 12;
            $end = 16;
            $offset = 20;
        } else {
            $response = $this->udp_scrape( $socket, $infohashes, $connection_id, $transaction_id, $host, $port );
            $keys = 'Nseeders/Ncompleted/Nleechers';
            $start = 8;
            $end = $offset = 12;
        }
        $results = $this->udp_scrape_data( $response, $infohashes, $host, $keys, $start, $end, $offset );

        return $results;
    }

    /**
     * Prepares the UDP connection
     *
     * @param string $host Domain or IP address of the tracker.
     * @param int    $port Optional. Port number of the tracker, Default 80.
     * @return array Created socket, transaction ID and connection ID.
     */
    private function prepare_udp( $host, $port ) {
        $socket = $this->udp_create_connection( $host, $port );
        $transaction_id = $this->udp_connection_request( $socket );
        $connection_id = $this->udp_connection_response( $socket, $transaction_id, $host, $port );

        return array( $socket, $transaction_id, $connection_id );
    }

    /**
     * Creates the UDP socket and establishes the connection
     *
     * @throws \Exception If the socket couldn't be created or connected to.
     *
     * @param string $host Domain or IP address of the tracker.
     * @param int    $port Port number of the tracker, Default 80.
     * @return resource $socket Created and connected socket.
     */
    private function udp_create_connection( $host, $port ) {
        if ( false === ( $socket = @socket_create( AF_INET, SOCK_DGRAM, SOL_UDP ) ) ) {
            throw new \Exception( "Couldn't create socket." );
        }

        $timeout = $this->timeout;
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
     * @param resource $socket The socket resource.
     * @return int The transaction ID.
     */
    private function udp_connection_request( $socket ) {
        $connection_id = "\x00\x00\x04\x17\x27\x10\x19\x80";
        $action = pack( 'N', 0 );
        $transaction_id = mt_rand( 0, 2147483647 );
        $buffer = $connection_id .  $action . pack( 'N', $transaction_id );
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
     * @param resource $socket The socket resource.
     * @param int      $transaction_id The transaction ID.
     * @param string   $host Domain or IP address of the tracker.
     * @param int      $port Port number of the tracker, Default 80.
     * @return string The connection ID.
     */
    private function udp_connection_response( $socket, $transaction_id, $host, $port ) {
        if ( false === ( $response = @socket_read( $socket, 16 ) ) ) {
            socket_close( $socket );
            throw new \Exception( 'Invalid scrape connection! (' . $host . ':' . $port . ').' );
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
     * Reads the socket response and returns the torrent data
     *
     * @throws \Exception If anything fails while reading the response.
     *
     * @param resource $socket The socket resource.
     * @param array    $hashes List (>1) or string of infohash(es).
     * @param string   $connection_id The connection ID.
     * @param int      $transaction_id The transaction ID.
     * @param string   $host Domain or IP address of the tracker.
     * @param int      $port Port number of the tracker, Default 80.
     * @return string Response data.
     */
    private function udp_scrape( $socket, $hashes, $connection_id, $transaction_id, $host, $port ) {
        $this->udp_scrape_request( $socket, $hashes, $connection_id, $transaction_id );

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

        return $response;
    }

    /**
     * Writes to the connected socket
     *
     * @throws \Exception If the socket couldn't be written to.
     *
     * @param resource $socket The socket resource.
     * @param array    $hashes List (>1) or string of infohash(es).
     * @param string   $connection_id The connection ID.
     * @param int      $transaction_id The transaction ID.
     */
    private function udp_scrape_request( $socket, $hashes, $connection_id, $transaction_id ) {
        $action = pack( 'N', 2 );

        $infohashes = '';
        foreach ( $hashes as $infohash ) {
            $infohashes .= pack( 'H*', $infohash );
        }

        $buffer = $connection_id . $action . pack( 'N', $transaction_id ) . $infohashes;
        if ( false === @socket_write( $socket, $buffer, strlen( $buffer ) ) ) {
            socket_close( $socket );
            throw new \Exception( "Couldn't write to socket." );
        }
    }

    /**
     * Writes the announce to the connected socket
     *
     * @throws \Exception If the socket couldn't be written to.
     *
     * @param resource $socket The socket resource.
     * @param array    $hashes List (>1) or string of infohash(es).
     * @param string   $connection_id The connection ID.
     * @return string Torrent(s) data.
     */
    private function udp_announce( $socket, $hashes, $connection_id ) {
        $action = pack( 'N', 1 );
        $downloaded = $left = $uploaded = "\x30\x30\x30\x30\x30\x30\x30\x30";
        $peer_id = $this->random_peer_id();
        $event = pack( 'N', 3 );
        $ip_addr = pack( 'N', 0 );
        $key = pack( 'N', mt_rand( 0, 2147483647 ) );
        $num_want = -1;
        $ann_port = pack( 'N', mt_rand( 0, 255 ) );

        $response_data = '';
        foreach ( $hashes as $infohash ) {
            $transaction_id = mt_rand( 0, 2147483647 );
            $buffer = $connection_id . $action . pack( 'N', $transaction_id ) . pack( 'H*', $infohash ) .
                $peer_id . $downloaded . $left . $uploaded . $event . $ip_addr . $key . $num_want . $ann_port;

            if ( false === @socket_write( $socket, $buffer, strlen( $buffer ) ) ) {
                socket_close( $socket );
                throw new \Exception( "Couldn't write announce to socket." );
            }

            $response = $this->udp_verify_announce( $socket, $transaction_id );
            if ( false === $response ) {
                continue;
            }

            $response_data .= $response;
        }
        socket_close( $socket );

        return $response_data;
    }

    /**
     * Generates a random peer ID
     *
     * @return string Generated peer ID.
     */
    private function random_peer_id() {
        $identifier = '-SP0054-';
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $peer_id = $identifier . substr( str_shuffle( $chars ), 0, 12 );

        return $peer_id;
    }

    /**
     * Verifies the correctness of the announce response
     *
     * @param resource $socket The socket resource.
     * @param int      $transaction_id The transaction ID.
     * @return string Response data.
     */
    private function udp_verify_announce( $socket, $transaction_id ) {
        if ( false === ( $response = @socket_read( $socket, 20 ) ) ) {
            return false;
        }

        if ( strlen( $response ) < 20 ) {
            return false;
        }

        $result = unpack( 'Naction/Ntransaction_id', $response );
        if ( 1 !== $result['action'] || $result['transaction_id'] !== $transaction_id ) {
            return false;
        }

        return $response;
    }

    /**
     * Reads the socket response and returns the torrent data
     *
     * @param string $response Data from the request response.
     * @param array  $hashes List (>1) or string of infohash(es).
     * @param string $host Domain or IP address of the tracker.
     * @param string $keys Keys for the unpacked information.
     * @param int    $start Start of the content we want to unpack.
     * @param int    $end End of the content we want to unpack.
     * @param int    $offset Offset to the next content part.
     * @return array Scraped torrent data.
     */
    private function udp_scrape_data( $response, $hashes, $host, $keys, $start, $end, $offset ) {
        $torrents_data = array();

        foreach ( $hashes as $infohash ) {
            $byte_string = substr( $response, $start, $end );
            $data = unpack( 'N', $byte_string );
            $content = $data[1];
            if ( ! empty( $content ) ) {
                $results = unpack( $keys, $byte_string );
                $torrents_data[ $infohash ] = $results;
            } else {
                $this->collect_infohash( $infohash );
                $this->errors[] = 'Invalid infohash (' . $infohash . ') for tracker: ' . $host . '.';
            }
            $start += $offset;
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
     * @return bool True or false, depending if errors are present or not.
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
