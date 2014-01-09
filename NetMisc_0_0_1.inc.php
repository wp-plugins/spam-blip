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

	// test stubs to support what follows
	public static function is_IP4_addr($addr)
	{
		$r = filter_var($addr
			, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
		return ($r !== false);
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
			}
			$mi = ~((1 << (32 - $mi)) - 1);
			$mc = long2ip($mi);
		// check traditional mask
		} else if ( self::is_IP4_addr($m) ) {
			$mc = $m;
			$mi = ip2long($m);
			$m = 32;
			// mechanical approach: bit counting loop;
			// PHP lacks log2(3), or we'd start with
			// 32 - (int)log2(~$mi + 1);
			while ( ! ($mi & 1) ) {
				if ( --$m === 0 ) {
					return false;
				}
				$mi >>= 1;
			}
			//checks
			if ( $m === 32 && ~$mi !== 0 ) {
				return false;
			} else if ( $m !== 32 && $mi !== ((1 << $m) - 1) ) {
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
	
	// normalize an IP4 addr with netmask, sep'd by '/'
	// if mask is missing it is considered /32; arg may
	// have second '/' to allow both classful and CIDR
	// mask expressions, and they may be in either order,
	// but IAC the first is used for normalization; if
	// arg "aout" is an array its [0] is assigned addr,
	// [1] gets CIDR (bitwidth) mask, [2] gets classful
	// (dotted quad) mask; returns string of form
	// "ADDR/CIDRMASK/CLASSFULMASK"
	// (BTW, speaking of classful masks does not imply one
	// must be actually classful; it may express a CIDR
	// bitwidth too)
	// The address before the firs '/' is not checked at all
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
		return (self::ip4_dots2int($addr) !== false);
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


if ( false && php_sapi_name() === 'cli' ) {
	// checks
	$chks = array(
		'46.118.113.0/255.255.0.0/15',
		'46.118.113.0/255.254.0.0/15',
		'46.118.113.0/16/255.254.0.0',
		'46.118.113.0/15/255.254.0.0',
		'46.118.113.0/255.255.255.254/31',
		'46.118.113.0/31/255.255.255.254',
		'46.118.113.0/128.0.0.0/1',
		'46.118.113.0/1/128.0.0.0',
		'46.118.113.0/29',
		'46.118.113.0/255.255.255.248/29',
		'46.118.113.0/255.255.255.247/29',
		'46.118.113.0/255.255.255.249/29',
		'46.118.113.0/255.240.0.0/12',
		'46.118.113.0/12',
		'46.118.113.0/255.255.248.0/29',
		'46.118.113.0/255.248.0.0/29',
		'46.118.113.0/14/255.232.0.0',
		'46.118.113.0/11/255.232.0.0',
		'46.118.113.0/255.224.0.0',
		'255.255.127.63'
	);

	foreach ( $chks as $v ) {
		$o = array();
		$r = NetMisc_0_0_1::netaddr_norm($v, $o, false);
		//$r = NetMisc_0_0_1::netaddr_norm($v);

		printf("arg '%s' === '%s': %s/%s/%s\n", $v, '' . $r,
			$r ? $o[0] : 'NG', $r ? $o[1] : 'NG', $r ? $o[2] : 'NG');
		//printf("arg '%s' === '%s'\n", $v, '' . $r);
	}
	
	// checks
	$chks = array(
		array('46.118.113.0', '12',
			array('46.118.113.140', '46.118.127.49',
				'46.119.113.12', '46.119.125.183')
		),
		array('46.118.113.0', '12',
			array('46.18.113.140', '46.218.127.49',
				'46.19.113.12', '46.219.125.183')
		),
		array('46.118.113.0', '10',
			array('46.118.113.140', '46.118.127.49',
				'46.119.113.12', '46.119.125.183')
		),
		array('46.118.113.0', '16',
			array('46.118.113.140', '46.118.127.49',
				'46.119.113.12', '46.119.125.183')
		)
	);
	
	foreach ( $chks as $aa ) {
		$net = $aa[0];
		$mask = $aa[1];
		foreach ( $aa[2] as $a ) {
			$t = NetMisc_0_0_1::is_addr_in_net($a, $net, $mask);
			printf(
				"%s -- %s is %sin net %s with mask %s\n",
				$t ? 'True' : 'False', $a, $t ? '' : 'not ',
				$net, $mask
			);
		}
	}
}

?>
