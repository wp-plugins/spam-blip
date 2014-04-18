<?php
#! /usr/bin/env php
/*
 *  NetMisc_0_0_1.inc.php
 *  
 *  Copyright 2014 Ed Hynan <edhynan@gmail.com>
 *  
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; specifically version 3 of the License.
 *  
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 *  MA 02110-1301, USA.
 */

/*
* Description: class with miscellaneous network functions
* Version: 0.0.1
* Author: Ed Hynan
* License: GNU GPLv3 (see http://www.gnu.org/licenses/gpl-3.0.html)
*/

/* text editor: use real tabs of 4 column width, LF line ends */

/**********************************************************************\
 *  Class defs                                                        *
\**********************************************************************/

/**
 * class for checking IPv4 addresses against a set
 * of RBL domains
 */
if ( ! class_exists('NetMisc_0_0_1') ) :
class NetMisc_0_0_1 {
	// help detect class name conflicts; called by using code
	private static $evh_opt_id = 0xED00AA33;
	public static function id_token () {
		return self::$evh_opt_id;
	}

	// take arg in $addr in either CIDR (int) or
	// dotted quad form check for errors, return
	// CIDR in $aout[0] snd dotted quad in $aout[1]
	// return true, or false on error
	public static function netmask_norm($addr, &$aout)
	{
		$m = $mc = $addr;
	
		// check CIDR mask
		if ( preg_match('/^[0-9]{1,2}$/', $m) ) {
			$mi = (int)$m;
			if ( $mi < 1 || $mi > 32 ) {
				return false;
			} else if ( $mi === 1 ) {
				// Avoid PHP 32-bit sign bit bugs, see comment below
				// beginning ``In the 2nd comparison ...''.
				$mi = 0x80000000;
			} else {
				$mi = ~((1 << (32 - $mi)) - 1);
			}
			$mc = long2ip($mi);
		// check traditional mask
		} else if ( self::is_addr_OK($m) ) {
			$mc = $m;
			$mi = ip2long($m);
			$m = 32;
			// mechanical approach: bit counting loop;
			// PHP lacks log2(3), so we cannot do
			// 32 - log2(~$mi + 1);
			while ( ! ($mi & 1) ) {
				if ( --$m === 0 ) {
					return false;
				}
				// PHP has signed integers, and sign copying
				// on right-shift, so high bit needs mask-off
				// on 32-bit hosts; it's harmless for 64-bit --
				// this depends on 2's complement signed ints.
				// Will this code ever encounter a host
				// that implements sign elsewise? And what
				// will PHP do on such a host? Docs describe
				// arithmetic right shift -- presumably it can
				// be expected.
				$mi = ($mi >> 1) & 0x7FFFFFFF;
			}
			//checks
			if ( $m === 32 && ~$mi !== 0 ) {
				return false;
			// In the 2nd comparison below if the (int) casts are
			// removed, the !== fails when $m is 31 and $mi is
			// 2147483647 (i.e. !== yields true when it should
			// be false). The casts should not be needed: the
			// operands are already ints. Must be a PHP bug where
			// sign bit diddling is incomplete until forced (?).
			// BTW, this is on a 32-bit host, PHP 5.2 and 5.3
			// (later vers. not tested). PHP 5.2 has the
			// additional bug that both '((1 << $m) - 1)' and
			// '(1 << $m)' yield -2147483648 when $m is 31, while
			// in 5.3 '((1 << $m) - 1)' is 2147483647 for $m==31.
			} else if ( $m < 32 && (int)($mi + 1) !== (int)(1 << $m) ) {
				return false;
			}
			$m = '' . $m;
		// mask error
		} else {
			return false;
		}
	
		$aout = array($m, $mc);
	
		return true;
	}

	// take range like 223.240.0.0 - 223.247.255.255
	// and return a ADDR/CIDRMASK/DOTTEDMASK string,
	// and place the values (in order above) in &$aout
	public static function netrange_norm($amin, $amax, &$aout)
	{
		$a = self::ip4_dots2int($amin);
		if ( $a === false ) {
			return false;
		}
		$b = self::ip4_dots2int($amax);
		if ( $b === false ) {
			return false;
		}

		$g = long2ip(~($a ^ $b) & 0xFFFFFFFF);
		// $g gives and receives
		if ( self::netmask_norm($g, $g) !== true ) {
			return false;
		}

		$aout = array($amin, $g[0], $g[1]);
		return $amin . '/' . $g[0] . '/' . $g[1];
	}
	
	// normalize an IP4 addr with netmask, sep'd by '/'
	// if mask is missing it is considered /32; arg may
	// have second '/' to allow both dotted and CIDR
	// mask expressions, and they may be in either order,
	// but IAC the first is used for normalization; if
	// arg "aout" is an array its [0] is assigned addr,
	// [1] gets CIDR (bitwidth) mask, [2] gets dotted
	// quad mask; returns string of form
	//     "ADDR/CIDRMASK/DOTTEDMASK"
	// The address before the first '/' is not checked at all
	// and may even be absent, but the '/' must be present
	// so that explode() will work
	public static function netaddr_norm($addr, &$aout = null, $chk2 = false)
	{
		$np = explode('/', $addr);
		if ( ! is_array($np) || count($np) < 1 ) {
			return false;
		}
		$a = trim($np[0]);
		$m = (count($np) < 2) ? '32' : trim($np[1]);
		$mc = (count($np) > 2) ? trim($np[2]) : false;
	
		$o = array();
		if ( self::netmask_norm($m, $o) !== true ) {
			if ( $chk2 === false ||
			     $mc === false ||
			     self::netmask_norm($mc, $o) !== true ) {
				return false;
			}
		}
		$m = $o[0];
		$mc = $o[1];
	
		if ( is_array($aout) ) {
			$aout[0] = $a;
			$aout[1] = $m;
			$aout[2] = $mc;
		}
	
		return '' . $a . '/' . $m . '/' . $mc;
	}
	
	// check whether IP4 address $addr is in the
	// network $net when $mask is applied; $mask
	// may be an integer (CIDR) or dotted quad
	public static function is_addr_in_net($addr, $net, $mask)
	{
		// use self::ip4_dots2int() for the address argument
		// because it's more strict than PHP ip2long()
		// is documented to *maybe* be (unverified), but
		// use ip2long() for the others for (assumed) efficiency
		if ( ($a = self::ip4_dots2int($addr)) === false ) {
			return false;
		}
		if ( ($n = ip2long($net)) === false ) {
			return false;
		}
		$o = array();
		if ( self::netmask_norm('' . $mask, $o) !== true ) {
			return false;
		}
		if ( ($m = ip2long($o[1])) === false ) {
			return false;
		}
	
		return (($a & $m) === ($n & $m));
	}
	
	// check IP4 address arg for sanity
	public static function is_addr_OK($addr) {
		// return (self::ip4_dots2int($addr) !== false);
		$r = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
		return ($r !== false);
	}

	// Unlike PHP ip2long(), this does
	// not possibly return non-false on incomplete addresses.
	public static function ip4_dots2int($addr)
	{
		if ( ! preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){3}$/', $addr) ) {
			return false;
		}
		$a = explode('.', $addr);
		if ( count($a) !== 4 ) {
			return false;
		}
	
		$v = 0;
		for ( $i = 0; $i < 4; $i++ ) {
			$oct = (int)$a[$i];
			if ( $oct > 255 ) {
				// check for $oct < 0 done w/ preg_match()
				return false;
			}
			$v |= ($oct << ((3 - $i) * 8));
		}
	
		return $v;
	}
}
endif; // if ( ! class_exists() ) :

/**
 * A class to check whether an IPv4 address is
 * routable, internal||private, or loopback
 */
if ( ! class_exists('IPReservedCheck_0_0_1') ) :
class IPReservedCheck_0_0_1 {
	// help detect class name conflicts; called by using code
	private static $evh_opt_id = 0xED00AA33;
	public static function id_token () {
		return self::$evh_opt_id;
	}

	// The misc class, above
	const NM = 'NetMisc_0_0_1';

	// Internal, private, with loopback at [0]
	protected $masks_dots = array(
		// loopback RFC 5735
		'127.255.255.255',
		// private RFC 1918
		'10.255.255.255','172.31.255.255','192.168.255.255',
		// broadcast to current network  RFC 1700
		'0.255.255.255',
		// Carrier-grade NAT RFC 6598
		'100.127.255.255',
		// autoconfiguration  RFC 5735
		'169.254.255.255',
		// DS-Lite transition RFC 6333
		'192.0.0.7',
		// "TEST-NET"  RFC 5737
		'192.0.2.255',
		// testing of inter-network ... separate subnets  RFC 2544
		'198.19.255.255',
		// "TEST-NET-2"  RFC 5737
		'198.51.100.255',
		// "TEST-NET-3"  RFC 5737
		'203.0.113.255',
		// Reserved for future use RFC 5735
		'255.255.255.255',
		// "limited broadcast" destination  RFC 5735
		'255.255.255.255',
		// following two are routable but special purpose
		// 6to4 anycast relays  RFC 3068
		'192.88.99.255',
		// multicast assignments  RFC 5771
		'239.255.255.255'
		);
	protected $nets_dots = array(
		'127.0.0.1',
		'10.0.0.0','172.16.0.0','192.168.0.0',
		'0.0.0.0',
		'100.64.0.0',
		'169.254.0.0',
		'192.0.0.0',
		'192.0.2.0',
		'198.18.0.0',
		'198.51.100.0',
		'203.0.113.0',
		'240.0.0.0',
		'255.255.255.255',
		'192.88.99.0',
		'224.0.0.0'
		);
	// the are host masks, i.e., net bits masked-out; thus the
	// These must be made at runtime
	protected $masks = null;
	protected $nets = null;
	
	// arg may be array of two arrays like $masks_dots and $nets_dots,
	// and the integer values in $masks and $nets will be regenerated
	// accordingly
	public function __construct($newdata = false)
	{
		if ( $newdata !== false ) {
			$this->masks_dots = $newdata[0];
			$this->nets_dots = $newdata[1];
		}
		
		$this->intgen();
	}

	protected function intgen()
	{
		$this->masks = array();
		foreach ( $this->masks_dots as $m ) {
			$this->masks[] = self::ip4_dots2int($m);
		}
		$this->nets = array();
		foreach ( $this->nets_dots as $n ) {
			$this->nets[] = self::ip4_dots2int($n);
		}
		for ( $i = 0; $i < count($this->masks); $i++ ) {
			$this->masks[$i] ^= $this->nets[$i];
		}
	}

	public function chk_simple($addr)
	{
		return ($this->chk_resv_addr($addr) === false) ? false : true;
	}

	public function chk_resv_addr($addr, $loc = true)
	{
		$mx = count($this->nets);
		$t = self::ip4_dots2int($addr);
		for ( $i = $loc ? 0 : 1; $i < $mx; $i++ ) {
			if ( ($t & ~$this->masks[$i]) === $this->nets[$i] ) {
				return $i;
			}
		}
		return false;
	}

	// check IP4 address arg for sanity
	public static function is_addr_OK($addr) {
		return NetMisc_0_0_1::is_addr_OK($addr);
	}

	// Unlike PHP ip2long(), this does
	// not possibly return non-false on incomplete addresses.
	public static function ip4_dots2int($addr)
	{
		return NetMisc_0_0_1::ip4_dots2int($addr);
	}
}
endif; // class_exists('IPReservedCheck_0_0_1')

?>
