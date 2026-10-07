<?php

class WPML_UUID {
	public function get( $object_id, $object_type, $timestamp = null ) {
		$timestamp = $timestamp ? $timestamp : time();
		$name      = $object_id . ':' . $object_type . ':' . $timestamp;

		return $this->get_uuid_v5( $name, wpml_get_site_id() );
	}

	/**
	 * RFC 4122 compliant UUIDs.
	 *
	 * The RFC 4122 specification defines a Uniform Resource Name namespace for
	 * UUIDs (Universally Unique Identifier), also known as GUIDs (Globally
	 * Unique Identifier).  A UUID is 128 bits long, and requires no central
	 * registration process.
	 *
	 * @package UUID
	 * @license https://www.gnu.org/licenses/gpl-2.0.txt GPLv2
	 * @author bjornjohansen
	 * @see https://bjornjohansen.no/uuid-as-wordpress-guid
	 *
	 * RFC 4122 compliant UUID version 5.
	 *
	 * @param  string $name    The name to generate the UUID from.
	 * @param  string $ns_uuid Namespace UUID. Default is for the NS when name string is a URL.
	 *
	 * @return string          The UUID string.
	 */
	public function get_uuid_v5( $name, $ns_uuid = '6ba7b811-9dad-11d1-80b4-00c04fd430c8' ) {
		$hash = sha1( $ns_uuid . $name );

		$octets = str_split( substr( $hash, 0, 16 ), 1 );

		$octets[6] = chr( ord( $octets[6] ) & 0x0f | 0x50 );

		$octets[8] = chr( ord( $octets[8] ) & 0x3f | 0x80 );

		$octets = array_map( 'bin2hex', $octets );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( implode( '', $octets ), 4 ) );
	}
}
