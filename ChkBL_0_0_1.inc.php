<?php
#! /usr/bin/env php
/*
 *  ChkBL_0_0_1.inc.php
 *  
 *  Copyright 2013 Ed Hynan <edhynan@gmail.com>
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
* Description: class used for DNSBL (RBL) check
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
if ( ! class_exists('ChkBL_0_0_1') ) :
class ChkBL_0_0_1 {
	// help detect class name conflicts; called by using code
	private static $evh_opt_id = 0xED00AA33;
	public static function id_token () {
		return self::$evh_opt_id;
	}

	// array of default RBL domains:
	// each array member has members domain, a hit pattern,
	// and special test operation of return; e.g., '3,&&'
	// means octet 3 (0 based, left to right) of the return should be
	// AND tested against octet 3 of the array member, and the other
	// octets must be equal -- for details, see comment at
	//     public function chk_rbl_result()
	// Sorting: these defaults are placed according to the
	// greatest likelihood of a hit, as observed in a sample
	// misleadingly small over an insignificant amount of time;
	// it is what it is.
	protected static $defdom = array(
		// list.blogspambl.com has example code that only tests
		// for 127., but seems to always return 127.0.0.2
		// http://blogspambl.com/
		array('list.blogspambl.com', '127.0.0.2', '1, I; 2 ,I; 3 ,I;'),
		// only 127.0.0.2 known
		// http://spam-champuru.livedoor.com/dnsbl/
		array('dnsbl.spam-champuru.livedoor.com', '127.0.0.2', null),
		// only 127.0.0.1 known (Note: *not* *.2)
		// Quote from URL below: ``You may use this RBL list
		// free of charge, currently without limit and I intend
		// to keep it that way.''
		// http://www.usenix.org.uk/content/rbl.html
		array('all.s5h.net', '127.0.0.1', null),
		// has been tried, but at end of list, therefore not
		// really evaluated -- but, has given hits
		// http://bbq.uso800.net/code.html
		// Update 2013/12/27: has had enough testing that I think
		// it should be in the default array
		array('niku.2ch.net', '127.0.0.2', null)
	);

	// as above, but found to be very 'strict', i.e.,
	// coverage that might be a little too broad; e.g.,
	// l2.apews.org gives hits for several TOR-related
	// IP's that few others list (dnsbl.tornevall.org
	// lists far fewer with the comment-spam 64-mask)
	protected static $strictdom = array(
		// apews is in strict list because I've found TOR
		// addresses listed *and* the FAQ states that
		// addresses may be listed by association with
		// known spammers or sites that host spamvertised
		// sites -- which I think is a good thing, but I
		// hesitate to make it default for users of a
		// weblog plugin (for which this is written)
		// only 127.0.0.2 known
		// http://www.apews.org/
		//array('l2.apews.org', '127.0.0.2', null),
		// dnsbl.tornevall.org in strict array due to its use
		// blocking TOR, which is generally not wanted
		// dnsbl.tornevall.org returns bit pattern in least-sig octet,
		// some bits are e.g., tor exit nodes, not tested in this
		// default; bit value 64 is ``IP marked as "abusive host''.
		// Primary target is web-form spamming (Includes dnsbl_remote)''
		// http://dnsbl.tornevall.org/
		array('dnsbl.tornevall.org', '127.0.0.64', '3,&')
	);

	// weblog spam DNSBLs that have not had much/any testing
	// (DNSBL sites can be found with web searches; a couple of lists:
	// http://multirbl.valli.org/list/ |
	// http://www.blalert.com/dnsbls
	// )
	protected static $otherdom = array(
		// only 127.0.0.2 known
		// http://bsb.empty.us/ OR http://bsb.spamlookup.net/
		array('bsb.empty.us', '127.0.0.2', null),
		// has been tried, but at end of list, therefore not
		// really evaluated -- but, has given hits
		// http://bbq.uso800.net/code.html
		// Update 2013/12/27: has had enough testing that I think
		// it should be in the default array
		//array('niku.2ch.net', '127.0.0.2', null)
	);

	protected $doms;      // from ctor arg, or ref to $defdom, or merge

	protected $errf;      // error message function: one string arg

	protected $ngix;      // indices of bad domain args

	public function __construct(
		$domarray = null, $merge = true, $errfunc = 'error_log')
	{
		$this->errf = $errfunc;

		if ( $domarray !== null ) {
			if ( $merge ) {
				$this->doms = array_merge($domarray, self::$defdom);
			} else {
				$this->doms = $domarray;
			}
		} else {
			$this->doms = &self::$defdom;
		}
		
		// TODO intl., utf8 arg
		$this->ngix = self::validate_dom_array($this->doms);
	}
	
	protected function errmsg($str) {
		call_user_func($this->errf, $str);
	}
	
	public function set_errmsg_func($errfunc = 'error_log') {
		$this->errf = $errfunc;
	}
	
	// reverse order of octets in an IPv4 dotted quad address
	public static function mk_reversed($addr) {
		return implode('.', array_reverse(explode('.', $addr)));
	}
	
	// make hostname to lookup in DNS from reversed IPv4 address
	// and a known RBL service domain
	// NOTE: the trailing dot indicates that no additional
	// search of domains known to the resolver should be
	// performed if the initial lookup (with trailing dot
	// removed) fails; do not remove the trailing dot as
	// doing so might lead to additional lookups not relevant
	// to the purpose of this code (RBL lookup) --
	// see hostname(7)
	public static function mk_rbl_host($revaddr, $dom) {
		return sprintf('%s.%s.', $revaddr, trim($dom));
	}

	// wrap PHP lookup to get a simple false on failure
	public static function chk_dns($hostname) {
		$r = gethostbyname($hostname);
		// PHP gethostbyname() returns its argument on failure
		if ( $r === $hostname ) {
			return false;
		}
		return $r;
	}
	
	// get copy of this class' static default array
	public static function get_def_array() {
		return self::$defdom;
	}
	
	// get copy of this class' static 'strict' default array
	public static function get_strict_array() {
		return self::$strictdom;
	}
	
	// get copy of this class' static 'other' default array
	public static function get_other_array() {
		return self::$otherdom;
	}
	
	// get copy of all DNSBL dommains, in array(default, strict, other)
	// unless merge === true
	public static function get_all_domain_array($merge = false) {
		return $merge ?
			array_merge(
			self::$defdom,
			self::$strictdom,
			self::$otherdom)
			:
			array(
			self::$defdom,
			self::$strictdom,
			self::$otherdom)
			;
	}
	
	// get array built in contructor
	public function get_dom_array() {
		return $this->doms;
	}
	
	// see validate_dom_arg() below
	// returns array of failure indices; empty array
	// if all OK *or* nothing to check in $aa, so caller beware
	public static function validate_dom_array($aa, $utf = false) {
		$r = array();
		if ( ! is_array($aa) ) {
			return $r;
		}
		$c = count($aa);
		if ( $c < 1 ) {
			return $r;
		}
		for ( $i = 0; $i < $c; $i++ ) {
			if ( self::validate_dom_arg($aa[$i], $utf) === false ) {
				$r[] = $i;
			}
		}
		return $r;
	}

	// validate a dom arg array as used by this class
	// NOTE this is not currently suitable for intl. non-ascii
	// domains: at least the code will need to convert name/labels
	// to IDN/punycode before checking lengths (and this would
	// need to be done before use too)
	// PHP >= 5.3.0 has *module* providing idn_to_ascii() and
	// idn_to_utf8(); but cannot rely on module availability, and
	// also still trying to support php 5.2 for a while (must
	// abandon that soon)
	// consider references to utf8 FPO
	// TODO: intl. (maybe not ever)
	public static function validate_dom_arg($a, $utf = false) {
		if ( ! is_array($a) ) {
			return false;
		}
		$c = count($a);
		if ( $c !== 3 ) {
			return false;
		}
		
		$d = $a[0];
		$r = $a[1];
		$t = $a[2];

		$mxname = 63;
		$maxall = 253;
		$mxpart = 127;

		// TODO: intl here
		// (is mb_strlen in core, or module?)
		if ( strlen($d) > $maxall ) {
			return false;
		}
		$ta = explode('.', $d);
		$tv = count($ta);
		if ( $tv > $mxpart || $tv < 2 ) {
			return false;
		}
		foreach ( $ta as $tv ) {
			if ( strlen($t) > $mxname ) {
				return false;
			}
		}

		// The UTF-8 pattern is bogus; I haven't even looked at any
		// rfc concerning intl. domain names; it's no more than a guess
		// not to mention any dependency on locale setup on server that
		// might not coincide with the user's expectations.
		// Moreover IDN conversions are not handled, so allowing utf8
		// is pointless -- consider references to utf8 FPO
		$pasc = '/^(([[:alnum:]]([[:alnum:]-]*[[:alnum:]])?)+\.)+[[:alnum:]]([[:alnum:]-]*[[:alnum:]])?$/';
		$putf = '/(*UTF8)^(([[:alnum:]]([[:alnum:]-]*[[:alnum:]])?)+\.)+[[:alnum:]]([[:alnum:]-]*[[:alnum:]])?$/';
		$p = $utf ? $putf : $pasc;
		if ( ! preg_match($p, $d) ) {
			return false;
		}

		// simple check that return value part id in
		// IP4 dotted quad form
		if ( filter_var($r, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
			=== false ) {
			return false;
		}

		// hard part: check optional return test code
		if ( $t === null || trim($tv) === '' ) {
			// this part is optional
			return true;
		}

		$ta = explode(';', $t);
		if ( count($ta) > 4 ) {
			return false;
		}

		foreach ( $ta as $tv ) {
			$tv = trim($tv);
			// allow empty fields
			if ( $tv === '' ) {
				continue;
			}
			$tv = explode(',', $tv);
			if ( count($tv) !== 2 ) {
				return false;
			}
			switch ( trim($tv[0]) ) {
				case '0': case '1': case '2': case '3':
					break;
				default:
					return false;
			}
			switch ( trim($tv[1]) ) {
				case '&': case '!&': case '==': case '!=':
				case '<': case '>': case '<=': case '>=':
				case 'I': case 'i':
					break;
				default:
					return false;
			}
			// Good!
		}

		return true;
	}
	
	// check lookup result, IPv4 dotted quad, against
	// data in array $adom
	// if $adom[2] is not null, it must be coded test instructions:
	//	array('dnsbl.tornevall.org', '127.0.0.64', '3,&;0,==;1,==;2,==')
	// string '3,&;0,==;1,==;2,==' is semicolon-separated, and
	// each element is comma-separated: 'octet-index,check-operation'
	// each octet of $addr is checked against the corresponding octet
	// of $adom[1]; if all tests are true, then return is true --
	// all 4 octets need not be present in the encoded test
	// string, the default is "==", so the example might have been
	// simply '3,&'
	// ops are: '&'. '!&', '==', '!=', '<', '>', '<=', '>=', and one
	// spacial op: 'I' (or 'i') meaning "ignore" and always true
	// there may be white-space for clarity
	// bad arguments give false return
	public function chk_rbl_result($addr, $adom) {
		$v = trim($addr);
		$opstr = $adom[2];
		if ( $adom[2] === null ) {
			$opstr = '0,==';
		}
		
		$ops = array('==', '==', '==', '==');
		$c = explode('.', $adom[1]);
		$v = explode('.', $v);
		
		if ( count($c) !== 4 ) {
			$str = sprintf('bad comp array "%s" in "%s"',
				$adom[1], __CLASS__ . '::' . __FUNCTION__);
			$this->errmsg($str);
			return false;
		}
		if ( count($v) !== 4 ) {
			$str = sprintf('bad result value array "%s" in "%s"',
				$v, __CLASS__ . '::' . __FUNCTION__);
			$this->errmsg($str);
			return false;
		}
		
		$opstr = explode(';', $opstr);
		$n = count($opstr);
		for ( $i = 0; $i < $n; $i++ ) {
			// skip blank fields, to be tolerant
			if ( trim($opstr[$i]) === '' ) {
				continue;
			}

			$t = explode(',', $opstr[$i]);
			if ( count($t) !== 2 ) {
				$str = sprintf('bad op string element "%s" in "%s"',
					$opstr[$i], __CLASS__ . '::' . __FUNCTION__);
				$this->errmsg($str);
				return false;
			}

			$k = 0;
			$idx = trim($t[0]);

			switch ( $idx ) {
				case '0': case '1': case '2': case '3':
					$k = (int)$idx;
					break;
				default:
					$str = sprintf('bad op index "%s" in "%s"',
						$t[0], __CLASS__ . '::' . __FUNCTION__);
					$this->errmsg($str);
					return false;
			}
			
			$ops[$k] = trim($t[1]);
		}
		
		$n = count($ops);
		for ( $i = 0; $i < $n; $i++ ) {
			$bres = false;
			$iv = (int)$v[$i];
			$ic = (int)$c[$i];

			switch ( $ops[$i] ) {
				case 'i':
				case 'I':  $bres = true;         break;
				case '&':  $bres = ($iv & $ic);  break;
				case '!&': $bres = !($iv & $ic); break;
				case '==': $bres = ($iv == $ic); break;
				case '!=': $bres = ($iv != $ic); break;
				case '<':  $bres = ($iv < $ic);  break;
				case '>':  $bres = ($iv > $ic);  break;
				case '<=': $bres = ($iv <= $ic); break;
				case '>=': $bres = ($iv >= $ic); break;
				default:
					$str = sprintf('bad operator "%s" in "%s"',
						$ops[$i], __CLASS__ . '::' . __FUNCTION__);
					$this->errmsg($str);
					return false;
			}
			if ( !$bres ) {
				return false;
			}
		}
		
		return true;
	}
	
	// check on indice into instance domain array
	// return false on error, or array[0] = RBL result
	// array[1] = satisfied result check (true||false)
	public function check_by_index($addr, $idx) {
		if ( in_array($idx, $this->ngix) ) {
			//$this->errmsg('BAD dom index: ' . $idx);
			return false;
		}

		$domarray = $this->get_dom_array();
		if ( ! isset($domarray[$idx]) ) {
			return false;
		}

		$res = array();

		$rip = self::mk_reversed($addr);
		$a = &$domarray[$idx];
		$hrbl = self::mk_rbl_host($rip, $a[0]);

		if ( ($res[] = self::chk_dns($hrbl)) == false ) {
			return false;
		}
		
		$res[] = $this->chk_rbl_result($res[0], $a);
		
		return $res;
	}
	
	// check all in the instance domain array; or return
	// when $num_true domains return true --
	// $num_true may be 'all', or if it is too great
	// or < 1, then all are checked; if $anyhit is not false
	// then a non-failure rbl lookup is added to the return
	// even if its result failed the value check --
	// return is an array of successes, each success is an
	// array in which [0] is the indice into rbl domain array
	// and [1] the return from the rbl lookup and [2] is a boolean
	// whether the result check passed; an empty array is returned
	// if all were false
	public function check_all($addr, $num_true = 1, $anyhit = false) {
		$cnt = count($this->get_dom_array());
		
		if ( $num_true === 'all' ) {
			$num_true = $cnt;
		}
		if ( $num_true < 1 || $num_true > $cnt ) {
			$num_true = $cnt;
		}
		
		$nhit = 0;
		$ret = array();
		for ( $i = 0; $i < $cnt; $i++ ) {
			$v = $this->check_by_index($addr, $i);

			if ( $v !== false ) {
				$ret[] = array($i, $v[0], $v[1]);

				if ( $anyhit || $v[1] ) {
					++$nhit;
				}

				if ( $nhit == $num_true ) {
					return $ret;
				}
			}
		}
		
		return $ret;
	}	

	// Simply check address over the known rbl domains,
	// return true for the first checked hit, false if none.
	public function check_simple($addr) {
		$v = $this->check_all($addr, 1, false);
		if ( empty($v) ) {
			return false;
		}
		return $v[0][2];
	}
	
	// Check if connection is a tor exit; TOR has crafted
	// the DNS so that the query must be done from a connected
	// server; the args with default false will use the
	// CGI envirronment if not passed -- $sa is server address,
	// $p is port
	public static function chk_tor_exit($addr, $sa = false, $p = false)
	{
		$sp  = $p ? $p : $_SERVER['SERVER_PORT'];
		$sa  = self::mk_reversed(
			$sa ? $sa : $_SERVER['SERVER_ADDR']
		);
		$chk = self::mk_reversed($addr);
		$dom = 'ip-port.exitlist.torproject.org';
		$hit = '127.0.0.2';

		$hst = sprintf('%s.%u.%s', $chk, $sp, $sa);
		$hst = self::mk_rbl_host($hst, $dom);

		$res = self::chk_dns($hst);
		if ( trim($res) == $hit ) {
			return true;
		}

		return false;
	}
}
endif; // if ( ! class_exists() ) :

if ( php_sapi_name() === 'cli' ) {
	$doms = ChkBL_0_0_1::get_all_domain_array(true);
	$t = new ChkBL_0_0_1($doms, false);
	$doms = $t->get_dom_array();

	for ( $i = 1; $i < $argc; $i++ ) {
		$arg = $argv[$i];
		$nt = 'unchecked';
		if ( (include 'NetMisc_0_0_1.inc.php') !== false ) {
			$ipchk = new IPReservedCheck_0_0_1();
			if ( ! $ipchk->is_addr_OK($arg) ) {
				printf("Found %s address is N.G.\n", $arg);
				continue;
			}
			$nt = $ipchk->chk_resv_addr($arg);
			if ( $nt !== false ) {
				$nt = $nt ? 'RESERVED' : 'LOOPBACK';
				printf("Found %s address '%s'\n", $nt, $arg);
				continue;
			} else {
				$nt = 'ROUTABLE';
			}
		}
		
		printf("Checking %s address '%s'\n", $nt, $arg);

		if ( false ) {
			$r = $t->check_simple($arg);
			printf("%s for '%s'\n", $r ? 'HIT' : 'OK', $arg);
		} else {
			$a = $t->check_all($arg, 'all', true);
			if ( empty($a) ) {
				printf("\tNo hits for '%s'\n", $arg);
				continue;
			}

			foreach ( $a as $result ) {
				$indice = $result[0];
				$valrbl = $result[1];
				$valcheck = $result[2];
				$dom = &$doms[$indice];

				printf("\tCheck %s '%s' '%s' '%s' -- '%s' for '%s'\n",
					$valcheck ? 'succeeded' : 'failed',
					$dom[0], $dom[1], $dom[2] ? $dom[2] : 'null',
					$valrbl, $arg);
			}
		}
	}
}

?>
