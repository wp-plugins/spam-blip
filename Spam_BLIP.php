<?php
/*
Plugin Name: Spam BLIP
Plugin URI: http://agalena.nfshost.com/b1/spam-blip-wordpress-comment-spam-plugin
Description: Stop comment spam before it is posted.
Version: 1.0.6
Author: Ed Hynan
Author URI: http://agalena.nfshost.com/b1/
License: GNU GPLv3 (see http://www.gnu.org/licenses/gpl-3.0.html)
Text Domain: spambl_l10n
*/

/*
 *      Spam_BLIP.php
 *      
 *      Copyright 2013 Ed Hynan <edhynan@gmail.com>
 *      
 *      This program is free software; you can redistribute it and/or modify
 *      it under the terms of the GNU General Public License as published by
 *      the Free Software Foundation; specifically version 3 of the License.
 *      
 *      This program is distributed in the hope that it will be useful,
 *      but WITHOUT ANY WARRANTY; without even the implied warranty of
 *      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *      GNU General Public License for more details.
 *      
 *      You should have received a copy of the GNU General Public License
 *      along with this program; if not, write to the Free Software
 *      Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 *      MA 02110-1301, USA.|g
 */


/* text editor: use real tabs of 4 column width, LF line ends */
/* human coder: keep line length <= 72 columns; break at params */


/**********************************************************************\
 *  requirements                                                      *
\**********************************************************************/


// check for naughty direct invocation; w/o this we'd soon die
// from undefined WP functions anyway, but let's check anyway
if ( basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME']) ) {
	die("Don't invoke me like that!\n");
}

// supporting classes found in files named "${cl}.inc.php"
// each class must define static method id_token() which returns
// the correct int, to help avoid name clashes
if ( ! function_exists( 'spamblip_paranoid_require_class' ) ) :
function spamblip_paranoid_require_class ($cl) {
	$id = 0xED00AA33;
	$meth = 'id_token';
	if ( ! class_exists($cl) ) {
		$d = plugin_dir_path(__FILE__).'/'.$cl.'.inc.php';
		require_once $d;
	}
	if ( method_exists($cl, $meth) ) {
		$t = call_user_func(array($cl, $meth));
		if ( $t !== $id ) {
			wp_die('class name conflict: ' . $cl . ' !== ' . $id);
		}
	} else {
		wp_die('class name conflict: ' . $cl);
	}
}
endif;

// these support classes are in separate files as they are
// not specific to this plugin, and may be used in others
spamblip_paranoid_require_class('ChkBL_0_0_1');
spamblip_paranoid_require_class('NetMisc_0_0_1');

/**********************************************************************\
 *  misc. functions                                                   *
\**********************************************************************/

/**
 * Only until PHP 5.2 compat is abandoned:
 * a non-class method that can be aliased (by string)
 * to a $var; 5.2 *cannot* call class methods, static or
 * not, through any alias
 */
if ( ! function_exists( 'Spam_BLIP_php52_htmlent' ) ) :
function Spam_BLIP_php52_htmlent ($text, $cset = null)
{
	// try to use get_option('blog_charset') only once;
	// it's not cheap enough even with WP's cache for
	// the number of times this might be called
	static $_blog_charset;
	if ( ! isset($_blog_charset) ) {
		$_blog_charset = get_option('blog_charset');
		if ( ! $_blog_charset ) {
			$_blog_charset = 'UTF-8';
		}
	}

	if ( $cset === null ) {
		$cset = $_blog_charset;
	}

	return htmlentities($text, ENT_QUOTES, $cset);
}
endif;


/**********************************************************************\
 *  Class defs: main plugin. widget, and support classes              *
\**********************************************************************/


/**
 * plugin main class
 * use of class has several advantages; most inspiring here is
 * the bargain-price namespace -- note that use of class does
 * not imply commitment to rigorous OOP methodology
 * (no 'class Int {
 * 			int v;
 * 			Int(int val = 0) : v(val) {}
 * 			operator int() const {return v;}
 * 			// . . .
 * };', thank you.)
 */
if ( ! class_exists('Spam_BLIP_class') ) :
class Spam_BLIP_class {
	// for debugging: set false for release
	const DBG = false;
	
	// web page as of release
	const plugin_webpage = 'http://agalena.nfshost.com/b1/spam-blip-wordpress-comment-spam-plugin';
	
	// the widget class name
	const Spam_BLIP_plugin_widget = 'Spam_BLIP_widget_class';
	
	// identifier for settings page
	const settings_page_id = 'Spam_BLIP_plugin1_settings_page';
	
	// option group name in the WP opt db
	const opt_group  = '_evh_Spam_BLIP_plugin1_opt_grp';
	// WP option names/keys
	// verbose (helpful?) section introductions?
	const optverbose = 'verbose';
	// this is hidden in settings page; used w/ JS for 'screen options'
	const optscreen1 = 'screen_opts_1';
	// filter comments_open?
	const optcommflt = 'commflt';
	// filter pings_open?
	const optpingflt = 'pingflt';
	// filter new user registration (optionally required to comment)?
	const optregiflt = 'regiflt';
	// pass, or 'whitelist', TOR exit nodes?
	const opttorpass = 'torpass';
	// record non-hit DNS lookups?
	const optnonhrec = 'nonhrec';
	// check existing comments marked as spam?
	const optchkexst = 'chkexst';
	// do *not* reject comments?
	const optrej_not = 'rej_not';
	// keep rbl hit data?
	const optrecdata = 'recdata';
	// use rbl hit data?
	const optusedata = 'usedata';
	// rbl hit data ttl
	const optttldata = 'ttldata';
	// rbl maximum data records
	const optmaxdata = 'maxdata';
	// optplugwdg -- use plugin's widget
	const optplugwdg = 'widget'; // plugin widget
	// log (and possibly mail notice) resv. IPs in REMOTE_ADDR?
	const optipnglog = 'ip_ng';
	// log blacklist hits?
	const optbliplog = 'log_hit';
	// bail out (wp_die()) on blacklist hits?
	const optbailout = 'bailout';
	// optional active RBL domains
	const opteditrbl = 'sp_bl_editrbl';
	// optional inactive (reserved) RBL domains
	const opteditrbr = 'sp_bl_editrbr';
	// optional active user whitelist
	const opteditwhl = 'sp_bl_editwhl';
	// optional inactive user whitelist
	const opteditwhr = 'sp_bl_editwhr';
	// optional   active user blacklist
	const opteditbll = 'sp_bl_editbll';
	// optional inactive user blacklist
	const opteditblr = 'sp_bl_editblr';
	// delete options on uninstall
	const optdelopts = 'delopts';
	// delete data store on uninstall
	const optdelstor = 'delstor';
	
	// table name suffix for the plugin data store
	const data_suffix  = 'Spam_BLIP_plugin1_datastore';
	// version for store table layout: simple incrementing integer
	const data_vs      = 3;
	// option name for data store version
	const data_vs_opt  = 'Spam_BLIP_plugin1_data_vers';

	// verbose (helpful?) section introductions?
	const defverbose = 'true';
	// this is hidden in settings page; used w/ JS for 'screen options'
	const defscreen1 = 'true';
	// filter comments_open?
	const defcommflt = 'true';
	// filter pingss_open?
	const defpingflt = 'true';
	// filter new user registration (optionally required to comment)?
	const defregiflt = 'false';
	// pass, or 'whitelist', TOR exit nodes?
	const deftorpass = 'false';
	// record non-hit DNS lookups?
	const defnonhrec = 'false';
	// check existing comments marked as spam?
	const defchkexst = 'true';
	// do *not* reject comments?
	const defrej_not = 'false';
	/* opts keep/use rbl hit data will probably not be useful,
	 * and will probably confuse: keep the code in place for now,
	 * but disable the settings page display, keeping the defaults
	 */
	const userecdata_enable = false;
	// keep rbl hit data?
	const defrecdata = 'true';
	// use rbl hit data?
	const defusedata = 'true';
	// rbl hit data ttl
	const defttldata = '1209600'; // 2 weeks in seconds
	// rbl maximum data records
	const defmaxdata = '200';
	// optplugwdg -- use plugin's widget
	const defplugwdg = 'false';  // plugin widget
	// log (and possibly mail notice) resv. IPs in REMOTE_ADDR?
	const defipnglog = 'true';
	// log blacklist hits?
	const defbliplog = 'false';
	// bail out (wp_die()) on blacklist hits?
	const defbailout = 'false';
	// optional active RBL domains
	const defeditrbl = '';
	// optional inactive (reserved) RBL domains
	const defeditrbr = '';
	// optional active user whitelist
	const defeditwhl = '';
	// optional inactive user whitelist
	const defeditwhr = '';
	// optional   active user blacklist
	const defeditbll = 'sp_bl_editbll';
	// optional inactive user blacklist
	const defeditblr = 'sp_bl_editblr';
	// delete options on uninstall
	const defdelopts = 'true';
	// delete data store on uninstall
	const defdelstor = 'true';
	
	// autoload class version suffix
	const aclv = '0_0_2b';

	// db maintenance interval; arg to WP cron
	// WHACK: only 'hourly' works; the others get
	// one initial invocation, and then never again!
	// No more time for this now
	const maint_intvl = 'hourly';
	//const maint_intvl = 'twicedaily.';
	//const maint_intvl = 'daily.';
	// array to hold arg to wp_schedule_event:
	private static $wp_cron_arg = array(self::maint_intvl);

	// Settings page object
	protected $spg = null;
	
	// An instance of the blacklist check class ChkBL_0_0_1
	protected $chkbl = null;

	// An instance of the bad IP check class IPReservedCheck_0_0_1
	protected $ipchk = null;
	protected $ipchk_done = false;

	// array of rbl lookup result is put here for reference
	// across callback methods; or set with result from
	// data store lookup as array(true||false)
	protected $rbl_result;
	// set array(true||false) when a data store lookup has been done
	// but not rbl
	protected $dbl_result;

	// if true do data store maintenance in shutdown hook
	protected $do_db_maintain;

	// js subdirectory
	const settings_jsdir = 'js';
	// js file for settings page
	const settings_jsname = 'screens.min.js';
	// js path, built in ctor
	protected $settings_js;
	// JS: name of class to control textare/button pairs
	const js_textpair_ctl = 'evhplg_ctl_textpair';

	// hold an instance
	private static $instance = null;

	// data store table name; built with $wpdb->prefix
	private $data_table = null;

	// this instance is fully initialized? (__construct($init == true))
	private $full_init;

	// correct file path (possibly needed due to symlinks)
	public static $plugindir  = null;
	public static $pluginfile = null;

	public function __construct($init = true) {
		// admin or public invocation?
		$adm = is_admin();

		// if arg $init is false then this instance is just
		// meant to provide options and such
		$pf = self::mk_pluginfile();
		// URL setup
		$t = self::settings_jsdir . '/' . self::settings_jsname;
		$this->settings_js = plugins_url($t, $pf);
		
		$this->rbl_result = false;
		$this->dbl_result = false;
		$this->do_db_maintain = false;
		$this->ipchk = new IPReservedCheck_0_0_1();
		
		if ( ($this->full_init = $init) !== true ) {
			// must do this
			$this->init_opts();
			return;
		}
		
		$cl = __CLASS__;

		if ( $adm ) {
			// Some things that must be *before* 'init'
			// NOTE cannot call current_user_can() because
			// its dependencies might not be ready at this point!
			// Use condition on current_user_can() in the callbacks
			// keep it clean: {de,}activation
			$aa = array($cl, 'on_deactivate');
			register_deactivation_hook($pf, $aa);
			$aa = array($cl, 'on_activate');
			register_activation_hook($pf,   $aa);

			$aa = array($cl, 'on_uninstall');
			register_uninstall_hook($pf,    $aa);
	
			// add 'Settings' link on the plugins page entry
			// cannot be in activate hook
			$name = plugin_basename($pf);
			add_filter("plugin_action_links_" . $name,
				array($cl, 'plugin_page_addlink'));
		}

		// some things are to be done in init hook
		add_action('init', array($this, 'init_hook_func'));

		// it's not enough to add this action in the activation hook;
		// that alone does not work.  IAC administrative
		// {de,}activate also controls the widget
		add_action('widgets_init', array($cl, 'regi_widget'));//, 1);
	}

	public function __destruct() {
		// FPO
		$this->spg = null;
	}
	
	// get array of defaults for the plugin options; if '$chkonly'
	// is true include only those options associated with a checkbox
	// on the settings page -- useful for the validate function
	protected static function get_opts_defaults($chkonly = false) {
		if ( $chkonly === true ) {
			return array(
				self::optverbose => self::defverbose,
				self::optscreen1 => self::defscreen1,
				self::optcommflt => self::defcommflt,
				self::optpingflt => self::defpingflt,
				self::optregiflt => self::defregiflt,
				self::opttorpass => self::deftorpass,
				self::optnonhrec => self::defnonhrec,
				self::optchkexst => self::defchkexst,
				self::optrej_not => self::defrej_not,
				self::optrecdata => self::defrecdata,
				self::optusedata => self::defusedata,
				self::optplugwdg => self::defplugwdg,
				self::optipnglog => self::defipnglog,
				self::optbliplog => self::defbliplog,
				self::optbailout => self::defbailout,
				self::optdelopts => self::defdelopts,
				self::optdelstor => self::defdelstor
			);
		}
		
		return array(
			self::optverbose => self::defverbose,
			self::optscreen1 => self::defscreen1,
			self::optcommflt => self::defcommflt,
			self::optpingflt => self::defpingflt,
			self::optregiflt => self::defregiflt,
			self::opttorpass => self::deftorpass,
			self::optnonhrec => self::defnonhrec,
			self::optchkexst => self::defchkexst,
			self::optrej_not => self::defrej_not,
			self::optrecdata => self::defrecdata,
			self::optusedata => self::defusedata,
			self::optttldata => self::defttldata,
			self::optmaxdata => self::defmaxdata,
			self::optplugwdg => self::defplugwdg,
			self::optipnglog => self::defipnglog,
			self::optbliplog => self::defbliplog,
			self::optbailout => self::defbailout,
			self::opteditrbl => self::defeditrbl,
			self::opteditrbr => self::defeditrbr,
			self::opteditwhl => self::defeditwhl,
			self::opteditwhr => self::defeditwhr,
			self::opteditbll => self::defeditbll,
			self::opteditblr => self::defeditblr,
			self::optdelopts => self::defdelopts,
			self::optdelstor => self::defdelstor
		);
	}

	// initialize plugin options from defaults or WPDB
	protected function init_opts() {
		$items = self::get_opts_defaults();
		$opts = self::get_opt_group();
		// note values converted to string
		if ( $opts ) {
			$mod = false;
			foreach ($items as $k => $v) {
				if ( ! array_key_exists($k, $opts) ) {
					$opts[$k] = '' . $v;
					$mod = true;
				}
				if ( $opts[$k] === '' && $v !== '' ) {
					$opts[$k] = '' . $v;
					$mod = true;
				}
			}
			if ( $mod === true ) {
				update_option(self::opt_group, $opts);
			}
		} else {
			$opts = array();
			foreach ($items as $k => $v) {
				$opts[$k] = '' . $v;
			}
			add_option(self::opt_group, $opts);
		}
		return $opts;
	}

	// initialize options/settings page
	protected function init_settings_page() {
		if ( $this->spg ) {
			return;
		}
		$items = self::get_opt_group();

		// use Opt* classes for page, sections, and fields;
		// these support classes are in separate files as they are
		// not specific to this plugin, and may be used in others
		spamblip_paranoid_require_class(self::mk_aclv('OptField'));
		spamblip_paranoid_require_class(self::mk_aclv('OptSection'));
		spamblip_paranoid_require_class(self::mk_aclv('OptPage'));
		spamblip_paranoid_require_class(self::mk_aclv('Options'));
		
		// mk_aclv adds a suffix to class names
		$Cf = self::mk_aclv('OptField');
		$Cs = self::mk_aclv('OptSection');
		// prepare fields to appear under various sections
		// of admin page
		$ns = 0;
		$sections = array();

		// General options section
		$nf = 0;
		$fields = array();
		$fields[$nf++] = new $Cf(self::optverbose,
				self::wt(__('Show verbose introductions:', 'spambl_l10n')),
				self::optverbose,
				$items[self::optverbose],
				array($this, 'put_verbose_opt'));
		$fields[$nf++] = new $Cf(self::optcommflt,
				self::wt(__('Blacklist check for comments:', 'spambl_l10n')),
				self::optcommflt,
				$items[self::optcommflt],
				array($this, 'put_comments_opt'));
		$fields[$nf++] = new $Cf(self::optpingflt,
				self::wt(__('Blacklist check for pings:', 'spambl_l10n')),
				self::optpingflt,
				$items[self::optpingflt],
				array($this, 'put_pings_opt'));
		$fields[$nf++] = new $Cf(self::optregiflt,
				self::wt(__('Blacklist check user registrations:', 'spambl_l10n')),
				self::optregiflt,
				$items[self::optregiflt],
				array($this, 'put_regi_opt'));
		$fields[$nf++] = new $Cf(self::opttorpass,
				self::wt(__('Whitelist (pass) TOR exit nodes:', 'spambl_l10n')),
				self::opttorpass,
				$items[self::opttorpass],
				array($this, 'put_torpass_opt'));
		$fields[$nf++] = new $Cf(self::optchkexst,
				self::wt(__('Check existing comment spam:', 'spambl_l10n')),
				self::optchkexst,
				$items[self::optchkexst],
				array($this, 'put_chkexst_opt'));
		$fields[$nf++] = new $Cf(self::optrej_not,
				self::wt(__('Check but do <em>not</em> reject:', 'spambl_l10n')),
				self::optrej_not,
				$items[self::optrej_not],
				array($this, 'put_rej_not_opt'));

		// section object includes description callback
		$sections[$ns++] = new $Cs($fields,
				'Spam_BLIP_plugin1_general_section',
				'<a name="general">' .
					self::wt(__('General Options', 'spambl_l10n'))
					. '</a>',
				array($this, 'put_general_desc'));

		// data section:
		$nf = 0;
		$fields = array();
		/* opts keep/use rbl hit data will probably not be useful,
		 * and will probably confuse: keep the code in place for now,
		 * but disable the settings page display, keeping the defaults
		 */
		if ( self::userecdata_enable ) {
			$fields[$nf++] = new $Cf(self::optrecdata,
					self::wt(__('Keep data:', 'spambl_l10n')),
					self::optrecdata,
					$items[self::optrecdata],
					array($this, 'put_recdata_opt'));
			$fields[$nf++] = new $Cf(self::optusedata,
					self::wt(__('Use data:', 'spambl_l10n')),
					self::optusedata,
					$items[self::optusedata],
					array($this, 'put_usedata_opt'));
		}
		$fields[$nf++] = new $Cf(self::optttldata,
				self::wt(__('Data records TTL:', 'spambl_l10n')),
				self::optttldata,
				$items[self::optttldata],
				array($this, 'put_ttldata_opt'));
		$fields[$nf++] = new $Cf(self::optmaxdata,
				self::wt(__('Maximum data records:', 'spambl_l10n')),
				self::optmaxdata,
				$items[self::optmaxdata],
				array($this, 'put_maxdata_opt'));
		$fields[$nf++] = new $Cf(self::optnonhrec,
				self::wt(__('Store (and use) non-hit addresses:', 'spambl_l10n')),
				self::optnonhrec,
				$items[self::optnonhrec],
				array($this, 'put_nonhrec_opt'));

		// data store usage
		$sections[$ns++] = new $Cs($fields,
				'Spam_BLIP_plugin1_datasto_section',
				'<a name="data_store">' .
					self::wt(__('Database Options', 'spambl_l10n'))
					. '</a>',
				array($this, 'put_datastore_desc'));
		
		// options for miscellaneous items
		$nf = 0;
		$fields = array();
		$fields[$nf++] = new $Cf(self::optplugwdg,
				self::wt(__('Use the included widget:', 'spambl_l10n')),
				self::optplugwdg,
				$items[self::optplugwdg],
				array($this, 'put_widget_opt'));
		$fields[$nf++] = new $Cf(self::optipnglog,
				self::wt(__('Log bad IP addresses:', 'spambl_l10n')),
				self::optipnglog,
				$items[self::optipnglog],
				array($this, 'put_iplog_opt'));
		$fields[$nf++] = new $Cf(self::optbliplog,
				self::wt(__('Log blacklisted IP addresses:', 'spambl_l10n')),
				self::optbliplog,
				$items[self::optbliplog],
				array($this, 'put_bliplog_opt'));
		$fields[$nf++] = new $Cf(self::optbailout,
				self::wt(__('Bail out on blacklisted IP:', 'spambl_l10n')),
				self::optbailout,
				$items[self::optbailout],
				array($this, 'put_bailout_opt'));

		// misc
		$sections[$ns++] = new $Cs($fields,
				'Spam_BLIP_plugin1_misc_section',
				'<a name="misc_sect">' .
					self::wt(__('Miscellaneous Options', 'spambl_l10n'))
					. '</a>',
				array($this, 'put_misc_desc'));
		
		// advanced items
		$nf = 0;
		$fields = array();
		$fields[$nf++] = new $Cf(self::opteditrbl,
				self::wt(__('Active and inactive blacklist domains:', 'spambl_l10n')),
				self::opteditrbl,
				$items[self::opteditrbl],
				array($this, 'put_editrbl_opt'));
		$fields[$nf++] = new $Cf(self::opteditbll,
				self::wt(__('Active and inactive user blacklist:', 'spambl_l10n')),
				self::opteditbll,
				$items[self::opteditbll],
				array($this, 'put_editbll_opt'));
		$fields[$nf++] = new $Cf(self::opteditwhl,
				self::wt(__('Active and inactive user whitelist:', 'spambl_l10n')),
				self::opteditwhl,
				$items[self::opteditwhl],
				array($this, 'put_editwhl_opt'));

		// advanced
		$sections[$ns++] = new $Cs($fields,
				'Spam_BLIP_plugin1_advanced_section',
				'<a name="advanced_sect">' .
					self::wt(__('Advanced Options', 'spambl_l10n'))
					. '</a>',
				array($this, 'put_advanced_desc'));
		
		// install opts section:
		// field: delete opts on uninstall?
		$nf = 0;
		$fields = array();
		$fields[$nf++] = new $Cf(self::optdelopts,
				self::wt(__('Delete setup options on uninstall:', 'spambl_l10n')),
				self::optdelopts,
				$items[self::optdelopts],
				array($this, 'put_del_opts'));
		$fields[$nf++] = new $Cf(self::optdelstor,
				self::wt(__('Delete database table on uninstall:', 'spambl_l10n')),
				self::optdelstor,
				$items[self::optdelstor],
				array($this, 'put_del_stor'));

		// inst sections
		$sections[$ns++] = new $Cs($fields,
				'Spam_BLIP_plugin1_inst_section',
				'<a name="install">' .
					self::wt(__('Plugin Install Settings', 'spambl_l10n'))
					. '</a>',
				array($this, 'put_inst_desc'));

		// prepare admin page specific hooks per page. e.g.:
		if ( false ) {
			$suffix_hooks = array(
				'admin_head' => array($this, 'settings_head'),
				'admin_print_scripts' => array($this, 'settings_js'),
				'load' => array($this, 'admin_load')
			);
		} else {
			$suffix_hooks = array(
				'admin_head' => array($this, 'settings_head'),
				'admin_print_scripts' => array($this, 'settings_js'),
			);
		}
		
		// prepare admin page
		// Note that validator applies to all options,
		// necessitating a big switch on option keys
		$Cp = self::mk_aclv('OptPage');
		$page = new $Cp(self::opt_group, $sections,
			self::settings_page_id,
			self::wt(__('Spam BLIP Plugin', 'spambl_l10n')),
			self::wt(__('Spam BLIP Configuration Settings', 'spambl_l10n')),
			array(__CLASS__, 'validate_opts'),
			/* pagetype = 'options' */ '',
			/* capability = 'manage_options' */ '',
			array($this, 'settings_page_output_callback')/* callback '' */,
			/* 'hook_suffix' callback array */ $suffix_hooks,
			self::wt(__('<em>Spam BLIP</em> Plugin Settings', 'spambl_l10n')),
			self::wt(__('Options controlling <em>Spam BLIP</em> functions.', 'spambl_l10n')),
			self::wt(__('Save Settings', 'spambl_l10n')));
		
		$Co = self::mk_aclv('Options');
		$this->spg = new $Co($page);
	}

	// filter for wp-admin/includes/screen.php get_column_headers()
	// to set text for Screen Options column
	public function screen_options_columns($a) {
		if ( ! is_array($a) ) {
			$a = array();
		}
		// checkbox id will 'verbose_show-hide'
		$a['verbose_show'] =
			self::wt(__('Section introductions', 'spambl_l10n'));
		return $a;
	}

	// filter for wp-admin/includes/screen.php show_screen_options()
	// to return true and enable the menu, or not
	public function screen_options_show($a) {
		if ( self::get_verbose_option() == 'true' ) {
			return true;
		}
		return false;
	}

	public function settings_head() {
		// get_current_screen() introduced in WP 3.1
		// (thus spake codex)
		// I have 3.0.2 to test with, and 3.3.1, nothing in between,
		// so 3.3 will be used as minimum
		$v = (3 << 24) | (3 << 16) | (0 << 8) | 0;
		$ok = self::wpv_min($v);

		$t = array(
			self::wt(sprintf(
		// TRANSLATORS: '%1$s' is the label of a checkbox option,
		// '%2$s' is the button label 'Save Settings';
		// The quoted string "Screen Options" should match an
		// interface label from the WP core, so if possible
		// use the WP core translation for that (likewise "Help").
			__('<p>The sections of this page each have an
			introduction which will, hopefully, be helpful.
			These introductions may
			be hidden or shown with a checkbox under the
			"Screen Options" tab (next to "Help") or with
			the "%1$s"
			option, which is the first option on this page.
			If "Screen Options" is absent, the verbose option
			is off: it must be on to enable that tab.
			</p><p>
			<em>Spam BLIP</em> will work well with
			the installed defaults, so it\'s not necessary
			to worry over the options on this page (but take
			a look at "Tips" in this help box). 
			</p><p>
			Remember, when any change is made, the new settings must
			be submitted with the "%2$s" button, near the end
			of this page, to take effect.
			</p>', 'spambl_l10n'),
			__('Show verbose introductions', 'spambl_l10n'),
			__('Save Settings', 'spambl_l10n')
			)),
			self::wt(sprintf(
		// TRANSLATORS: all '%s' are labels of checkbox options
			__('<p>Although the default settings
			will work well, consider enabling these:
			<ul>
			<li>"%1$s" -- enable this for most broad coverage against
			spam; but, leave this disabled if you <em>know</em> that
			you want to accept user registrations for some
			purposes even if the address might be blacklisted</li>
			<li>"%2$s" -- because The Onion Router is a very
			important protection for <em>real</em> people, even if
			spammers abuse it and cause associated addresses
			to be blacklisted</li>
			<li>"%3$s" -- if you have access to the error log
			of your site server, this will give you a view
			of what the plugin has been doing</li>
			<li>"%4$s" -- a small bit of CPU time and network
			traffic will be saved when an IP address is
			identified as a spammer (but in the case of a false
			positive, this will seem rude)</li>
			</ul>
			<p>
			Those options default to false/disabled (which is
			why your attention is called to them).
			</p><p>
			If you find that a welcome visitor could not comment
			because their IP address was in a blacklist, add their
			address to the "Active User Whitelist" in the
			"Advanced Options" section.
			</p><p>
			<em>Spam BLIP</em> is expected work well as a first
			line of defense against spam, and should complement
			spam filter plugins that work by analyzing comment content.
			It might not work in concert with other
			DNS blacklist plugins.
			</p>', 'spambl_l10n'),
			__('Check blacklist for user registration', 'spambl_l10n'),
			__('Whitelist TOR addresses', 'spambl_l10n'),
			__('Log blacklist hits', 'spambl_l10n'),
			__('Bail (wp_die()) on blacklist hits', 'spambl_l10n')
			))
		);

		// TRANSLATORS: first '%s' is the the phrase
		// 'For more information:'; using translation
		// from default textdomain (WP core)
		$tt = self::wt(sprintf(
			__('<p><strong>%s</strong></p><p>
			More information can be found on the
			<a href="%s" target="_blank">web page</a>.
			Please submit feedback or questions as comments
			on that page.
			</p>', 'spambl_l10n'),
			__('For more information:'),
			self::plugin_webpage
		));
	
		// finagle the "Screen Options" tab
		$h = 'manage_' . $this->spg->get_page_suffix() . '_columns';
		add_filter($h, array($this, 'screen_options_columns'));
		$h = 'screen_options_show_screen';
		add_filter($h, array($this, 'screen_options_show'), 200);

		// put help tab content, for 3.3.1 or greater . . .
		if ( $ok ) {
			$scr = get_current_screen();
			$scr->add_help_tab(array(
				'id'      => 'overview',
				'title'   => __('Overview'), // use transl. from core
				'content' => $t[0]
				// content may be a callback
				)
			);
	
			$scr->add_help_tab(array(
				'id'      => 'help_tab_tips',
				'title'   => __('Tips', 'spambl_l10n'),
				'content' => $t[1]
				// content may be a callback
				)
			);
	
			$scr->set_help_sidebar($tt);
		
		// . . . or, lesser
		} else {
			global $current_screen;
			add_contextual_help($current_screen,
				'<h6>' . __('Overview') . '</h6>' . $t[0] .
				'<h6>' . __('Tips', 'spambl_l10n') . '</h6>' . $t[1] .
				$tt);
		}
	}

	public function settings_js() {
		$jsfn = self::settings_jsname;
		$j = $this->settings_js;
		wp_enqueue_script($jsfn, $j);
	}

	// This function is placed here below the function that sets-up
	// the options page so that it is easy to see from that function.
	// It exists only for the echo "<a name='aSubmit'/>\n";
	// line which mindbogglingly cannot be printed from
	// Options::admin_page() -- it is somehow *always* stripped out!
	// After hours I cannot figure this out; but, having added this
	// function as the page callback, I can add the anchor after
	// calling $this->spg->admin_page() (which is Options::admin_page())
	// BUT it still does not show in the page if the echo is moved
	// into Options::admin_page() and placed just before return!
	// Baffled.
	public function settings_page_output_callback() {
		$r = $this->spg->admin_page();
		echo "<a name='aSubmit'/>\n";
		return $r;
	}

	/**
	 * General hook/filter callbacks
	 */
	
	// deactivate cleanup
	public static function on_deactivate() {
		if ( ! current_user_can('activate_plugins') ) {
			return;
		}

		$wreg = __CLASS__;
		$name = plugin_basename(self::mk_pluginfile());
		$aa = array($wreg, 'plugin_page_addlink');
		remove_filter("plugin_action_links_" . $name, $aa);

		self::unregi_widget();

		unregister_setting(self::opt_group, // option group
			self::opt_group, // opt name; using group passes all to cb
			array($wreg, 'validate_opts'));

		// un-setup cron job for e.g, db table maintenance
		// NOTE on action hook name: from WP codex doc:
		// "For some reason there seems to be a problem
		// on some systems where the hook must not
		// contain underscores or uppercase characters."
		wp_clear_scheduled_hook('spamblipplugincronact',
			self::$wp_cron_arg);
		// action for cron callback
		$aa = array($wreg, 'action_static_cron');
		remove_action('spamblipplugincronact', $aa);
	}

	// activate setup
	public static function on_activate() {
		if ( ! current_user_can('activate_plugins') ) {
			return;
		}

		$wreg = __CLASS__;
		$aa = array($wreg, 'regi_widget');
		add_action('widgets_init', $aa, 1);

		// setup cron job for e.g, db table maintenance
		if ( ! wp_next_scheduled('spamblipplugincronact',
			self::$wp_cron_arg) ) {
			// set *previous* midnight, *local* time -- there is
			// something very fragile about the wp cron facility:
			// tough to get it to actually work
			$tm = time();
			wp_schedule_event(
				$tm, self::maint_intvl, 'spamblipplugincronact',
					self::$wp_cron_arg);
		}
	}

	// uninstall cleanup
	public static function on_uninstall() {
		if ( ! current_user_can('install_plugins') ) {
			return;
		}

		self::unregi_widget();
		
		$opts = self::get_opt_group();

		if ( $opts && $opts[self::optdelstor] != 'false' ) {
			$pg = self::get_instance();
			// bye data
			$pg->db_delete_table();
			delete_option(self::data_vs_opt);
		}

		if ( $opts && $opts[self::optdelopts] != 'false' ) {
			delete_option(self::opt_group);
		}

		// un-setup cron job for e.g, db table maintenance
		$aa = array(self::maint_intvl);
		wp_clear_scheduled_hook('spamblipplugincronact',
			self::$wp_cron_arg);
	}

	// add link at plugins page entry for the settings page
	public static function plugin_page_addlink($links) {
		// Add a link to this plugin's settings page --
		// up to v 1.0.5.1 bug: get_option('siteurl') was used to
		// build value with hardcoded path fragments -- N.G.,
		// might not have been full URL w/ https, etc.,
		// menu_page_url(), added after 1.0.5.1, fixes that.
		$opturl = menu_page_url(self::settings_page_id, false);

		if ( $opturl ) {
			$opturl = sprintf('<a href="%s">%s</a>',
			    $opturl,
			    __('Settings', 'spambl_l10n')
			);
			array_unshift($links, $opturl); 
		}
		return $links; 
	}

	// register the Spam_BLIP_plugin widget
	public static function regi_widget($fargs = array()) {
		global $wp_widget_factory;
		if ( ! isset($wp_widget_factory) ) {
			return;
		}
		if ( self::get_widget_option() == 'false' ) {
			return;
		}
		if ( function_exists('register_widget') ) {
			$cl = self::Spam_BLIP_plugin_widget;
			register_widget($cl);
		}
	}

	// unregister the Spam_BLIP_plugin widget
	public static function unregi_widget() {
		global $wp_widget_factory;
		if ( ! isset($wp_widget_factory) ) {
			return;
		}
		if ( function_exists('unregister_widget') ) {
			$cl = self::Spam_BLIP_plugin_widget;
			unregister_widget($cl);
		}
	}

	// to be done at WP init stage
	public function init_hook_func() {
		self::load_translations();
		$this->init_opts();

		// admin or public invocation?
		$adm = is_admin();

		$cl = __CLASS__; // for static methods callbacks

		if ( $adm ) {
			// Settings/Options page setup
			if ( current_user_can('manage_options') ) {
				$this->init_settings_page();
			}
	
			// create/update table as nec.
			if ( self::get_recdata_option() != 'false' ||
				 self::get_usedata_option() != 'false' ) {
				$this->db_create_table();
	
				// user should not have 'WP_ALLOW_REPAIR'
				// defined all the time; naughty types could
				// repeatedly invoke wp-admin/maint/repair.php
				// to increase server load and lockup DB and
				// who knows, maybe even exploit MySQL bugs.
				// IAC, for real use, it should be OK to
				// include our table -- WP does CHECK TABLE
				// and REPAIR TABLE and maybe ANALYZE TABLE
				if ( defined('WP_ALLOW_REPAIR') ) {
					$aa = array($this, 'filter_tables_to_repair');
					add_filter('tables_to_repair', $aa, 100);
				}
			}
		} else { // if ( $adm )
			$aa = array($this, 'action_pre_comment_on_post');
			add_action('pre_comment_on_post', $aa, 100);
	
			$aa = array($this, 'action_comment_closed');
			add_action('comment_closed', $aa, 100);
	
			$aa = array($this, 'filter_comments_open');
			add_filter('comments_open', $aa, 100);
	
			$aa = array($this, 'filter_pings_open');
			add_filter('pings_open', $aa, 100);
	
			// optional check on new registrations
			if ( self::check_filter_user_regi() ) {
				$aa = array($this, 'filter_user_regi');
				add_filter('register', $aa, 100);
				$aa = array($this, 'action_user_regi');
				add_action('login_form_register', $aa, 100);
			}
		} // if ( $adm )

		// WP does this hook from a php register_shutdown_function()
		// callback, so it's invoked even after wp_die()
		$aa = array($this, 'action_shutdown');
		add_action('shutdown', $aa, 200);

		// setup cron job for e.g, db table maintenance
		if ( ! wp_next_scheduled('spamblipplugincronact',
			self::$wp_cron_arg) ) {
			$tm = time();
			wp_schedule_event(
				$tm, self::maint_intvl, 'spamblipplugincronact',
					self::$wp_cron_arg);
		}
		// action for cron callback
		$aa = array($cl, 'action_static_cron');
		add_action('spamblipplugincronact', $aa);
	}

	// add_filter('tables_to_repair', $scf, 1);
	// Allows adding table name to WP core table repair routing
	public function filter_tables_to_repair($tbls) {
		$tbls[] = $this->db_tablename();
		return $tbls;
	}

	public static function load_translations() {
		// The several load*() calls here are inspired by this:
		//   http://geertdedeckere.be/article/loading-wordpress-language-files-the-right-way
		// So, provide for custom *.mo installed in either
		// WP_LANG_DIR or WP_PLUGIN_DIR/languages or WP_PLUGIN_DIR,
		// and do translations in the plugin directory last.
		
		// hack test whether .mo load call has been done
		static $WP_textdomain_done;
		static $plugin_langdir_textdomain_done;
		static $plugin_dir_textdomain_done;
		static $plugin_textdomain_done;

		$dom = 'spambl_l10n';

		if ( ! isset($WP_textdomain_done) && defined('WP_LANG_DIR') ) {
			$loc = apply_filters('plugin_locale', get_locale(), $dom);
			// this file path is built in the manner shown at the
			// URL above -- it does look strange
			$t = sprintf('%s/%s/%s-%s.mo',
				WP_LANG_DIR, $dom, $dom, $loc);
			$WP_textdomain_done = load_textdomain($dom, $t);
		}
		if ( ! isset($plugin_langdir_textdomain_done) ) {
			$t = 'languages/';
			$plugin_langdir_textdomain_done =
				load_plugin_textdomain($dom, false, $t);
		}
		if ( ! isset($plugin_dir_textdomain_done) ) {
			$plugin_dir_textdomain_done =
				load_plugin_textdomain($dom, false, false);
		}
		if ( ! isset($plugin_textdomain_done) ) {
			$t = basename(trim(self::mk_plugindir(), '/')) . '/locale/';
			$plugin_textdomain_done =
				load_plugin_textdomain($dom, false, $t);
		}
	}

	/**
	 * Utility and misc. helper procs
	 */
	
	// get other end IP address
	public static function get_conn_addr() {
		$addr = $_SERVER['REMOTE_ADDR'];
		if ( $addr && (count(explode('.', $addr)) == 4) ) {
			return $addr;
		}
		return false;
	}

	// rx check for IP6 address; return boolean
	public static function check_ip6_address($addr) {
		$addr = trim($addr);

		$pat = '[A-Fa-f0-9]{1,4}';
		if ( preg_match(sprintf('/^(::%s|%s::)$/', $pat, $pat), $addr) ) {
			return true;
		}
		if ( preg_match(sprintf('/^%s::%s$/', $pat, $pat), $addr) ) {
			return true;
		}

		if ( ! preg_match(sprintf('/^%s:.*:%s$/', $pat, $pat), $addr) ) {
			return false;
		}

		$a = explode(':', $addr);
		$c = count($a);
		if ( $c < 3 || $c > 8 ) {
			return false;
		}

		$x = $c - 1;
		$blank = false;
		for ( $i = 1; $i < $x; $i++ ) {
			if ( $a[$i] == '' ) {
				if ( $blank ) { // allow one '::'
					return false;
				}
				$blank = true;
				continue;
			}
			if ( ! preg_match(sprintf('/^%s$/', $pat), $a[$i]) ) {
				return false;
			}
		}

		return true;
	}

	// append version suffix for Options classes names
	protected static function mk_aclv($pfx) {
		$s = $pfx . '_' . self::aclv;
		return $s;
	}
	
	// help for plugin file path/name; __FILE__ alone
	// is not good enough -- see comment in body
	public static function mk_plugindir() {
		if ( self::$plugindir !== null ) {
			return self::$plugindir;
		}
	
		$pf = __FILE__;
		// using WP_PLUGIN_DIR due to symlink problems in
		// some installations; after much grief found fix at
		// https://wordpress.org/support/topic/register_activation_hook-does-not-work
		// in a post by member 'silviapfeiffer1' -- she nailed
		// it, and noone even replied to her!
		if ( defined('WP_PLUGIN_DIR') ) {
			$ad = explode('/', rtrim(plugin_dir_path($pf), '/'));
			$pd = $ad[count($ad) - 1];
			$pf = WP_PLUGIN_DIR . '/' . $pd;
		} else {
			// this is similar to common methods w/  __FILE__; but
			// can cause regi* failures due to symlinks in path
			$pf = rtrim(plugin_dir_path($pf), '/');
		}
		
		// store and return corrected file path
		return self::$plugindir = $pf;
	}
	
	// See comment above
	public static function mk_pluginfile() {
		if ( self::$pluginfile !== null ) {
			return self::$pluginfile;
		}
	
		$pf = self::mk_plugindir();
		$ff = basename(__FILE__);
		
		// store and return corrected file path
		return self::$pluginfile = $pf . '/' . $ff;
	}

	// hex encode a text string
	public static function et($text) {
		return rawurlencode($text);
	}
	
	// 'html-ize' a text string
	public static function ht($text, $cset = null) {
		static $_blog_charset;
		if ( ! isset($_blog_charset) ) {
			$_blog_charset = get_option('blog_charset');
			if ( ! $_blog_charset ) {
				$_blog_charset = 'UTF-8';
			}
		}
	
		if ( $cset === null ) {
			$cset = $_blog_charset;
		}

		return htmlentities($text, ENT_QUOTES, $cset);
	}
	
	// 'html-ize' a text string; with WordPress char translations
	public static function wt($text) {
		if ( function_exists('wptexturize') ) {
			return wptexturize($text);
		}
		return self::ht($text);
	}
	
	// get WP software version as int (at least 32 bit, major < 128)
	public static function wpv_int() {
		static $wp_vi = null;
		if ( $wp_vi === null ) {
			global $wp_version;
			$v = 0;
			$va = explode('.', $wp_version);
			for ( $i = 0; $i < 4; $i++ ) {
				if ( ! isset($va[$i]) ) {
					break;
				}
				$v |= ((int)$va[$i] << ((3 - $i) * 8));
			}
			$wp_vi = $v;
		}
		return $wp_vi;
	}
	
	// compare WP software version -- 1 if wp > cmp val,
	// -1 if <, else 0
	public static function wpv_cmp($cv) {
		$wv = self::wpv_int();
		$cv = (int)$cv;
		if ( $cv < $wv ) return 1;
		if ( $cv > $wv ) return -1;
		return 0;
	}
	
	// compare WP software version
	public static function wpv_min($cv) {
		return (self::wpv_cmp($cv) >= 0) ? true : false;
	}
	
	protected static function is_mozz() {
		static $is_so = null;
		if ( $is_so === null ) {
			// cannot match either Mozilla or Gecko because
			// they appear in the compatibility assertions
			// of not necessarily compatible browsers
			$p = '/\bFirefox\b/';
			$r = preg_match($p, $_SERVER['HTTP_USER_AGENT']);
			$is_so = $r ? true : false;
		}
		return $is_so;
	}
	
	// error messages; where {wp_}die is not suitable
	public static function errlog($err) {
		$e = sprintf('Spam_BLIP WP plugin: %s', $err);
		error_log($e, 0);
	}
	
	// debug messages for development: tests class const 'DBG'
	public static function dbglog($err) {
		if ( self::DBG ) {
			self::errlog('DBG: ' . $err);
		}
	}
	
	// helper to make self
	public static function instantiate($init = true) {
		if ( ! self::$instance ) {
			$cl = __CLASS__;
			self::$instance = new $cl($init);
		}
		return self::$instance;
	}

	// helper get instance of this class
	public static function get_instance($init = false) {
		global $Spam_BLIP_plugin1_evh_instance_1;
		$pg = null;

		if ( ! isset($Spam_BLIP_plugin1_evh_instance_1)
			|| $Spam_BLIP_plugin1_evh_instance_1 == null ) {
			$pg = self::instantiate($init);
		} else {
			$pg = $Spam_BLIP_plugin1_evh_instance_1;
		}

		return $pg;
	}

	// get microtime() if possible, else just time()
	public static function best_time() {
		if ( function_exists('microtime') ) {
			// PHP 4 better be dead
			// PHP 5: arg true gets a float return
			return microtime(true);
		}
		return (int)time();
	}

	// get future epoch timestamp for next noon or midnight
	// $tm should generally be time() now, or leave it null
	// if $local is true then get local offset value, else UTC
	// if $noon is false get next midnight, else next noon
	/**
	 * COMMENTED: this was to be used setting up the WP cron
	 * schedule, but since I can get *nothing* but 'hourly'
	 * to work, this is pointless -- it remains here just in case . . .
	public static function tm_next_12meridian(
		$tm = null, $local = true, $noon = false
		) {
		if ( $tm === null ) {
			$tm = time();
		}
		$t = $tm - ($tm % 86400) + ($noon ? 43200 : 86400);
		// can happen for noon:
		if ( $t < $tm ) {
			$t += 86400;
		}
		if ( $local ) {
			$t -= idate("Z", $tm);
		}
		return $t;
	}
	*/

	// optional additional response to unexpected REMOTE_ADDR;
	// after errlog()
	protected function handle_REMOTE_ADDR_error($msg) {
		// TODO: make option; send email
	}

	// For the optional blacklist check on user registration:
	// the decision whether to do the filter|action must consider
	// a number of factors, and the check is needed in a few
	// places, so provide a simple boolean answer.
	public static function check_filter_user_regi() {
		// This macro is checked in wp-login.php, but is not defined
		// anywhere in WP core, because it is provided for the special
		// case of moving across hosts, and is to be defined by person
		// doing the move, and should be removed when finished --
		// SHOULD NOT be defined otherwise, or at
		// least should be false.
		if ( defined('RELOCATE') && RELOCATE ) {
			return false;
		}
		// pointless to filter logged in user:
		if ( is_user_logged_in() ) {
			return false;
		}
		// this check is not really needed; installing filter anyway
		// is cheap, and it should simply not get invoked if users
		// cannot register
		if ( false && ! get_option('users_can_register') ) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Settings page callback functions:
	 * validators, sections, fields, and page
	 */

	// static callback: validate options main
	public static function validate_opts($opts) {	
		$a_out = array();
		$a_orig = self::get_opt_group();
		$nerr = 0;
		$nupd = 0;

		// empty happens if all fields are checkboxes and none checked
		if ( empty($opts) ) {
			$opts = array();
		}

		// checkboxes need value set - nonexistant means false
		$ta = self::get_opts_defaults(true); // gets only checkbox ctls
		foreach ( $ta as $k => $v ) {
			if ( array_key_exists($k, $opts) ) {
				continue;
			}
			$opts[$k] = 'false';
		}
		// remainder of controls
		$ta = self::get_opts_defaults(false); // gets all
		foreach ( $ta as $k => $v ) {
			if ( array_key_exists($k, $opts) ) {
				continue;
			}
			$opts[$k] = $v;
		}
	
		// special handling of errors in textarea pairs: see near end
		$pairerr = array();
		
		foreach ( $opts as $k => $v ) {
			if ( ! array_key_exists($k, $a_orig) ) {
				// this happens for the IDs of extra form items
				// in use, if not associated with an option
				continue;
			}
			$ot = trim($v);
			$oo = $a_orig[$k];

			switch ( $k ) {
				// Option buttons
				case self::optttldata . '_text': // FPO; see below:
					break;
				case self::optttldata:
					switch ( $ot ) {
						case ''.(3600):       	//'One hour'
						case ''.(3600*6):     	//'Six hours'
						case ''.(3600*12):    	//'Twelve hours'
						case ''.(3600*24):    	//'One day'
						case ''.(3600*24*7):  	//'One week'
						case ''.(3600*24*7*2):	//'Two weeks'
						case ''.(3600*24*7*4):	//'Four weeks'
							$a_out[$k] = $ot;
							$nupd += ($ot === $oo) ? 0 : 1;
							break;
						default:               	//'Set a value:'
							$ot = trim($opts[self::optttldata.'_text']);
							// 9 decimal digits > 30 years in secs
							$re = '/^[+-]?[0-9]{1,9}$/';
							if ( preg_match($re, $ot) == 1 ) {
								if ( (int)$ot < 0 ) { $ot = '0'; }
								$a_out[$k] = ltrim($ot, '+');
								$nupd += ($ot === $oo) ? 0 : 1;
								break;
							}
							$e = __('bad TTL option: "%s"', 'spambl_l10n');
							$e = sprintf($e, $ot);
							self::errlog($e);
							add_settings_error(self::ht($k),
								sprintf('%s[%s]', self::opt_group, $k),
								self::ht($e), 'error');
							$a_out[$k] = $oo;
							$nerr++;
							break;
					}
					break;
				case self::optmaxdata . '_text': // FPO; see below:
					break;
				case self::optmaxdata:
					switch ( $ot ) {
						case '10':
						case '50':
						case '100':
						case '200':
						case '500':
						case '1000':
							$a_out[$k] = $ot;
							$nupd += ($ot === $oo) ? 0 : 1;
							break;
						default:               //'Set a value:'
							$ot = trim($opts[self::optmaxdata.'_text']);
							// 9 decimal digits, billion - 1; plenty
							$re = '/^[+-]?[0-9]{1,9}$/';
							if ( preg_match($re, $ot) == 1 ) {
								if ( (int)$ot < 0 ) { $ot = '0'; }
								$a_out[$k] = ltrim($ot, '+');
								$nupd += ($ot === $oo) ? 0 : 1;
								break;
							}
							$e = __('bad maximum: "%s"', 'spambl_l10n');
							$e = sprintf($e, $ot);
							self::errlog($e);
							add_settings_error(self::ht($k),
								sprintf('%s[%s]', self::opt_group, $k),
								self::ht($e), 'error');
							$a_out[$k] = $oo;
							$nerr++;
							break;
					}
					break;
				// textarea pairs
				case self::opteditwhl:
				case self::opteditwhr:
				case self::opteditbll:
				case self::opteditblr:
					$lnm = ($k == self::opteditwhl ||
							$k == self::opteditwhr)
							? __('whitelist', 'spambl_l10n')
							: __('blacklist', 'spambl_l10n');
					$t = explode("\n", $ot);
					$to = array();
					for ( $i = 0; $i < count($t); $i++ ) {
						$l = trim($t[$i]);
						if ( $l == '' ) {
							continue;
						}
						$chk = NetMisc_0_0_1::is_addr_OK($l);
						if ( $chk === false ) {
							// New v. 1.0.3: entry may be
							// a range netmin-netmax
							$nma = '/[0-9\.]+[ \t]*-[ \t]*[0-9\.]+/';
							if ( preg_match($nma, $l) ) {
								$nma = explode('-', $l);
								$chk = NetMisc_0_0_1::netrange_norm(
									trim($nma[0]), trim($nma[1]), $nma);
								if ( $chk !== false ) {
									$l = $chk;
								}
							}
							// New v. 1.0.2: entry may be
							// addr/netmask[/netmask], 2nd mask
							// optional so that one may be CIDR and
							// the other traditional, order unimportant.
							// Of course in this usage addr is meant
							// as a network rather than host. In
							// principle net number needn't be a full
							// dotted quad -- enough for the netmask
							// is needed -- but code will be simpler
							// and less error-prone by requiring a
							// full proper quad.
							$nma = explode('/', $l);
							$chk = NetMisc_0_0_1::ip4_dots2int($nma[0]);
							if ( $chk !== false ) {
								$chk = NetMisc_0_0_1::netaddr_norm($l,
									$nma, true);
							}
							if ( $chk !== false ) {
								// netaddr_norm() places CIDR in [1]
								if ( (int)$nma[1] === 32 ) {
									// mask of 32 implies host, not net
									$l = $nma[0];
								} else {
									// returns clean net/CIDR/maskquad
									$l = $chk;
								}
								// non-false return was not truly true
								$chk = true;
							}
						}
						if ( $chk === false ) {
							// TRANSLATORS: %1$s is either
							// 'whitelist' or 'blacklist', and
							// %2$s is an IP4 dotted quad address
							$e = __('bad user %1$s address set: "%2$s"', 'spambl_l10n');
							$e = sprintf($e, $lnm, $l);
							self::errlog($e);
							add_settings_error(self::ht($k),
								sprintf('%s[%s]', self::opt_group, $k),
								self::ht($e), 'error');
							// error counter
							$nerr++;
							// for special handling
							$pairerr[] = $k;
							// signal error post-loop
							$t = false;
							break;
						}
						$to[] = $l;
					}
					if ( $t === false ) {
						$a_out[$k] = $oo;
						break;
					}
					$t = is_array($oo) ? $oo : array();
					if ( $to !== $t ) {
						$a_out[$k] = $to;
						$nupd++;
					} else {
						$a_out[$k] = $oo;
					}
					break;
				// textarea pairs
				case self::opteditrbl:
				case self::opteditrbr:
					$t = explode("\n", $ot);
					$to = array();
					for ( $i = 0; $i < count($t); $i++ ) {
						$ln = trim($t[$i]);
						if ( $ln == '' ) {
							continue;
						}
						$l = array_map('trim', explode('@', $ln));
						// TODO: format checks
						if ( (! isset($l[1])) || $l[1] == '' ) {
							$l[1] = '127.0.0.2';
						}
						if ( (! isset($l[2])) || $l[2] == '' ) {
							$l[2] = null;
						}
						while ( count($l) > 3 ) {
							array_pop($l);
						}
						$chk = ChkBL_0_0_1::validate_dom_arg($l);
						if ( $chk === false ) {
							// record error for WP
							$e = __('bad blacklist domain set: "%s"', 'spambl_l10n');
							$e = sprintf($e, $ln);
							self::errlog($e);
							add_settings_error(self::ht($k),
								sprintf('%s[%s]', self::opt_group, $k),
								self::ht($e), 'error');
							// error counter
							$nerr++;
							// for special handling
							$pairerr[] = $k;
							// signal error post-loop
							$t = false;
							break;
						}
						$to[] = $l;
					}
					if ( $t === false ) {
						$a_out[$k] = $oo;
						break;
					}
					$t = is_array($oo) ? $oo : array();
					if ( $to !== $t ) {
						$a_out[$k] = $to;
						$nupd++;
					} else {
						$a_out[$k] = $oo;
					}
					break;
				// Checkboxes
				case self::optrecdata:
				case self::optusedata:
					// these two are special: subject to
					// static const
					if ( self::userecdata_enable !== true ) {
						$ot = 'true';
					}
				// hidden opts for 'screen options' -- boolean
				case self::optscreen1:
					$a_out[$k] = ($ot == 'false') ? 'false' : 'true';
					break;
				case self::optverbose:
				case self::optcommflt:
				case self::optpingflt:
				case self::optregiflt:
				case self::opttorpass:
				case self::optnonhrec:
				case self::optchkexst:
				case self::optrej_not:
				case self::optplugwdg:
				case self::optipnglog:
				case self::optbliplog:
				case self::optbailout:
				case self::optdelopts:
				case self::optdelstor:
					if ( $ot != 'true' && $ot != 'false' ) {
						$e = sprintf('bad checkbox option: %s[%s]',
							$k, $v);
						self::errlog($e);
						add_settings_error(self::ht($k),
							sprintf('%s[%s]', self::opt_group, $k),
							self::ht($e), 'error');
						$a_out[$k] = $oo;
						$nerr++;
					} else {
						$a_out[$k] = $ot;
						$nupd += ($oo === $ot) ? 0 : 1;
					}
					break;
				default:
					$e = sprintf(
						__('bad key in option validation: "%s"', 'spambl_l10n')
						, $k);
					self::errlog($e);
					add_settings_error(self::ht($k),
						sprintf('ERR_%s[%s]',
							self::opt_group, self::ht($k)),
						self::ht($e), 'error');
					$nerr++;
			}
		}
		
		// for text area pairs: a line might have been moved
		// from one to the other; if this other now has an
		// error, the line is lost with the rejected changes:
		// therefore, both must have changes rejected
		foreach ( $pairerr as $k ) {
			switch ( $k ) {
				case self::opteditwhl:
					$k = self::opteditwhr;
					break;
				case self::opteditwhr:
					$k = self::opteditwhl;
					break;
				case self::opteditbll:
					$k = self::opteditblr;
					break;
				case self::opteditblr:
					$k = self::opteditbll;
					break;
				case self::opteditrbl:
					$k = self::opteditrbr;
					break;
				case self::opteditrbr:
					$k = self::opteditrbl;
					break;
				default:
					continue;
			}

			$v = array_key_exists($k, $a_orig) ? $a_orig[$k] : '';
			if ( array_key_exists($k, $a_out) && $a_out[$k] !== $v ) {
				$nupd--;
				$a_out[$k] = $v;
			}
		}

		// now register updates
		if ( $nupd > 0 ) {
			$fmt = $nerr == 0
				? _n('%u setting updated successfully',
					'%u settings updated successfully',
					$nupd, 'spambl_l10n')
				: _n('One (%d) setting updated',
					'Some settings (%d) updated',
					$nupd, 'spambl_l10n');
			$str = sprintf($fmt, $nupd);
			$type = $nerr == 0 ? 'updated' : 'updated error';
			add_settings_error(self::opt_group, self::opt_group,
				self::wt($str), $type);
		}
		
		return $a_out;
	}

	/**
	 * Options section callbacks
	 */
	
	// callback: put html for general setting section description
	public function put_general_desc() {
		if ( self::get_verbose_option() !== 'true' ) {
			return;
		}

		// coopt this proc to put 'screen options' hidden opt:
		$eid = self::optscreen1 . '_ini';
		$val = self::get_screen1_option() == 'true' ? "true" : "false";

		printf('<input id="%s" value="%s" type="hidden">%s',
			$eid, $val, "\n"
		);

		$did = 'Spam_BLIP_General_Desc';
		echo '<div id="' . $did . '">';

		$t = self::wt(__('Introduction:', 'spambl_l10n'));
		printf('<p><strong>%s</strong>%s</p>', $t, "\n");

		$t = self::wt(__('The "Show verbose introductions"
			option selects whether
			verbose introductions
			should be displayed with the various settings
			sections. The long introductions, one of which 
			this paragraph is a part,
			will not be shown if the option is not
			selected.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Blacklist check for comments" option 
			enables the main functionality of the plugin. When
			<em>WordPress</em> core code checks whether comments
			are open or closed, this plugin will check the connecting
			IP address against DNS-based blacklists of weblog
			comment spammers, and if it is found, will tell
			<em>WordPress</em> that comments are
			closed.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Blacklist check for pings" option 
			is similar to "Blacklist check for comments",
			but for pings.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Blacklist check user registrations"
			option enables the blacklist checks before the
			user registration form is presented; for example, if
			your site is configured to require login or registration
			to post a comment. <strong>Note</strong> that this check
			is done for all requests of the registration form, even if
			not related to an attempt to comment. Because that
			might not be appropriate, this option is off by
			default.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Whitelist TOR exit nodes" option 
			enables a special lookup to try to determine if the
			connecting address is a TOR exit node.
			If it is found to be one (there are some
			false negatives), it is
			allowed to comment or ping. This option might be
			important if your site has content that is political,
			or in some way controversial, as visitors you would
			welcome might need to use TOR. TOR is an important
			tool for Internet anonymity, but unfortunately spammers
			have abused it, and  so some DNS blacklist operators
			include any TOR address. This option probably will let
			more spam comments be posted, but it might work well
			along with another sort of spam blocker, such as one
			that analyses comment content, as a second line of
			defense.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('With "Check existing comment spam"
			enabled connecting addresses are checked against
			comments already stored by <em>WordPress</em> and
			marked as spam. If a match is found with a comment
			that is not too old (according to the TTL setting,
			see "Data records TTL" below),
			the connection
			is considered a spammer, and the address is added
			to the hit database.
			The default is true.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('With "Check but do <em>not</em> reject"
			enabled all checks are performed, but hits are not
			rejected (if comments are already closed, that is not
			changed). This allows useful records to be collected
			while disabling the main functionality.
			', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		echo '</div>';
		?>
		<script type="text/javascript">
		addto_evhplg_obj_screenopt("verbose_show-hide", "<?php echo $did ?>");
		</script>
		<?php

		$t = self::wt(__('Go forward to save button.', 'spambl_l10n'));
		printf('<p><a href="#aSubmit">%s</a></p>%s', $t, "\n");
	}

	// callback: store and use data section description
	public function put_datastore_desc() {
		$cnt = $this->db_get_rowcount();
		if ( $cnt ) {
			$t = self::wt(
				_n('(There is %u record in the database table)',
				   '(There are %u records in the database table)',
				   $cnt, 'spambl_l10n')
			);
			printf('<p>%s</p>%s', sprintf($t, $cnt), "\n");
		}

		if ( self::get_verbose_option() !== 'true' ) {
			return;
		}

		$did = 'Spam_BLIP_Datastore_Desc';
		echo '<div id="' . $did . '">';

		$t = self::wt(__('Introduction:', 'spambl_l10n'));
		printf('<p><strong>%s</strong>%s</p>', $t, "\n");

		/* opts keep/use rbl hit data will probably not be useful,
		 * and will probably confuse: keep the code in place for now,
		 * but disable the settings page display, keeping the defaults
		 */
		if ( self::userecdata_enable ) {
		$t = self::wt(__('These options enable, disable or configure
			the storage of blacklist lookup results in the
			<em>WordPress</em> database, or the use of the
			stored data before DNS lookup.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Keep data" option enables recording of
			hit data such as the connecting IP address, and the times
			the address was first seen and last seen.
			(This data is also used if included widget is
			enabled.)', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Use data" option enables a check in the
			stored data; if a hit is found there then the
			DNS lookup is not performed.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");
		} else { // if ( self::userecdata_enable )
		$t = self::wt(__('These options configure
			the storage of blacklist lookup results in a table
			in the
			<em>WordPress</em> database.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");
		} // if ( self::userecdata_enable )

		$t = self::wt(__('"Data records TTL" sets an expiration time for
			records in the database. The records should not be kept
			permanently, or even for very long, because the IP
			address might not belong to the spammer, but rather
			a conscientious ISP (also a victim of abuse by the spammer)
			that must be able to reuse the IP address. DNS
			blacklist operators might use a low TTL (Time To Live) in
			the records of relevant lists for this reason. The default
			value is one day (86400 seconds). If you do not want
			any of the presets, the text field accepts a value
			in seconds, where zero (0) or less will disable the
			TTL.
			When an address is being checked, the database lookup
			requests only records that have last been seen
			within the TTL time; also, when database maintenance is
			performed, expired records are removed.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Maximum data records" option limits how
			many records will be kept in the database. It is likely that
			as the data grow larger, the oldest records will no
			longer be needed. Records are judged old based on
			the time an address was last seen. Use your judgement with
			this: if you always get large amounts of spam, a larger
			value might be warranted. The number of records may grow
			larger than this setting by a small calculated amount before
			being trimmed back to the number set here', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Store (and use) non-hit addresses"
			option will cause commenter addresses to be stored even
			if the address was not found in the spammer lists. This
			will save additional DNS lookups for repeat commenters.
			This should only be used if there is a perceptible delay
			caused by the DNS lookups, because an address might
			turn out to be associated with a spammer and subsequently
			be added to the online spam blacklists, but this option
			would allow that address to post comments until its
			record expired from the plugin\'s database. Also, an
			address might be dynamic and therefore an association
			with a welcome commenter would not be valid.
			The default is false.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		echo '</div>';
		?>
		<script type="text/javascript">
		addto_evhplg_obj_screenopt("verbose_show-hide", "<?php echo $did ?>");
		</script>
		<?php

		$t = self::wt(__('Go forward to save button.', 'spambl_l10n'));
		printf('<p><a href="#aSubmit">%s</a></p>%s', $t, "\n");
		$t = self::wt(__('Go back to top (General section).', 'spambl_l10n'));
		printf('<p><a href="#general">%s</a></p>%s', $t, "\n");
	}

	// callback: put html for miscellanous setting description
	public function put_misc_desc() {
		if ( self::get_verbose_option() !== 'true' ) {
			return;
		}

		$did = 'Spam_BLIP_Misc_Desc';
		echo '<div id="' . $did . '">';

		$t = self::wt(__('Introduction:', 'spambl_l10n'));
		printf('<p><strong>%s</strong>%s</p>', $t, "\n");

		$t = self::wt(__('The "Use the included widget" option controls
			whether the multi-widget included with the plugin is
			enabled. The widget will display some counts of the
			stored data, and plugin settings. You should consider
			whether you want that data on public display, but
			if you find that acceptable, the widget should give
			a convenient view of the effectiveness of the plugin.
			Of course, the widget must have been set up for use
			(under the Appearance menu, Widgets item) for this
			setting to have an effect.
			', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Log bad IP addresses" option enables
			log messages when
			the remote IP address provided in the CGI/1.1
			environment variable "REMOTE_ADDR" is wrong. Software
			used in a hosting arrangement can cause this, even
			while the connection ultimately works. This
			plugin checks whether the connecting address is in
			a reserved, loopback, or other special purpose
			network range. If it is, the DNS blacklist check
			is not performed, as it would be pointless, and a
			message is issued to the error log.
			For a site on the "real" Internet, there is probably
			no reason to turn this option off. In fact, if
			these log messages are seen (look for "REMOTE_ADDR"),
			the hosting administrator
			or technical contact should be notified that their
			system has a bug.
			This option should be off when developing a site on
			a private network or single machine, because in this
			case error log messages might be issued for addresses
			that are valid on the network. With this option off,
			the plugin will still check the address and skip
			the blacklist DNS lookup if the address is reserved.
			', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('"Log blacklisted IP addresses" selects logging
			of blacklist hits with the remote IP address. This
			is only informative, and will add unneeded lines
			in the error log. New plugin users might like to
			enable this temporarily to see the effect the plugin
			has had.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Bail out on blacklisted IP"
			option will have the plugin terminate the blog output
			when the connecting IP address is blacklisted. The
			default is to only disable comments, and allow the
			page to be produced normally. This option will save
			some amount of network load,
			and spammers do not want or need your
			content anyway, but if there is a rare false positive,
			the visitor, also a spam victim in this case, will
			miss your content.
			', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		echo '</div>';
		?>
		<script type="text/javascript">
		addto_evhplg_obj_screenopt("verbose_show-hide", "<?php echo $did ?>");
		</script>
		<?php

		$t = self::wt(__('Go forward to save button.', 'spambl_l10n'));
		printf('<p><a href="#aSubmit">%s</a></p>%s', $t, "\n");
		$t = self::wt(__('Go back to top (General section).', 'spambl_l10n'));
		printf('<p><a href="#general">%s</a></p>%s', $t, "\n");
	}

	// callback: put html for advanced setting description
	public function put_advanced_desc() {
		if ( self::get_verbose_option() !== 'true' ) {
			return;
		}

		$did = 'Spam_BLIP_Advanced_Desc';
		echo '<div id="' . $did . '">';

		$t = self::wt(__('Introduction:', 'spambl_l10n'));
		printf('<p><strong>%s</strong>%s</p>', $t, "\n");

		$t = self::wt(__('The "Active and inactive blacklist domains"
			text fields can be used to edit the DNS blacklist domains
			and the interpretation of the values they return. The left
			text field is for active domains; those that will be
			checked for a comment posting address. The right text field
			is for domains considered inactive; they are stored but
			not used. Each domain entry occupies one line in the fields,
			and lines can be moved between the active and inactive
			fields with the buttons just below the fields. Of course,
			new domains can be added (along with rules for evaluating
			their return values); and domains may be deleted, although
			it might be better to leave domains in the inactive field
			unless it is certain that they are defunct or unsuitable.
			', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('Each "Active and inactive blacklist domains"
			entry line consists of three parts separated by a \'@\'
			character. Only the first part is required. The first
			part is the domain name for the DNS lookup.
			The second part is a value to compare with the return
			of a DNS lookup that succeeds; if this part is not
			present the default is "127.0.0.2". It must be in the
			form of an IP4 dotted quad address.
			The third part is a set of operations for
			comparing the DNS lookup return with the value in
			the second part. If present, the third part must
			consist of one or more fields separated by a \';\'
			character, and each such field must have two parts
			separated by a \',\' character. The first part of
			each field is an index into the dotted quad form,
			starting at zero (0) and preceeding from left to
			right. The second part of each field is a comparison
			operator, which may be <em>one</em> of
			"<code>==</code>" (is equal to),
			"<code>!=</code>" (not equal to),
			"<code>&lt;</code>" (numerically less than),
			"<code>&gt;</code>" (greater than),
			"<code>&lt;=</code>" (less than or equal to),
			"<code>&gt;=</code>" (greater than or equal to),
			"<code>&amp;</code>" (bitwise AND),
			"<code>!&amp;</code>" (not bitwise AND),
			or
			"<code>I</code>" (character "i", case insensitive, meaning
			"ignore": no comparison at this index). The fields may
			contain whitespace for clarity.
			The default
			for any field that is not present is "<code>==</code>",
			so if the whole third part is absent then a DNS lookup
			return is checked for complete equality with the value
			of the second part.
			', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Active and inactive user blacklist"
			and "Active and inactive user whitelist"
			text fields can be used to add addresses that will
			always be denied, or always allowed, respectively.
			Like the blacklist domains fields, only those in the
			left side "active" text areas are used, and addresses in
			the right side "inactive" areas are not used, but stored.
			</p><p>
			The black and white lists also accept whole subnetworks.
			This might be very useful if, for example, it seems that
			spammers are using or abusing a whole subnet, or if you
			need to allow an organization network even if some of its
			addresses appear in the DNS blacklists. Specify a subnet
			as "<code>N.N.N.N/(CIDR or N.N.N.N)</code>"
			where the network number appears
			to the left of the slash and the network mask appears
			to the right of the slash. The network mask may be given
			in CIDR notation (number of bits) or the traditional
			dotted quad form. A subnet may also be given as a range
			from minimum to maximum network address, as in
			"<code>N.N.N.N - N.N.N.N</code>". (A subnet specified
			as a range is often found in <strong>WHOIS</strong>
			output.)
			When the settings are submitted, these
			arguments are normalized so that
			"<code>N.N.N.N/CIDR/N.N.N.N</code>"
			will appear. You may specify both CIDR and dotted quad
			network masks, separated by an additional slash, if you are
			not sure which is correct, and compare the result after
			submitting the settings.
			</p><p>
			You should be thoughtful when
			specifying a subnetwork and its mask because errors will
			affect numerous addresses. Enable
			"Log blacklisted IP addresses" in the
			"Miscellaneous Options" section and then check your site
			error log to see if multiple hits seem to be coming from
			the same subnet, and check the <em>WHOIS</em> service
			to get an idea of what the network and mask should be.
			If you really understand what you are doing you may
			of course decide values on your judgement.
			', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		echo '</div>';
		?>
		<script type="text/javascript">
		addto_evhplg_obj_screenopt("verbose_show-hide", "<?php echo $did ?>");
		</script>
		<?php

		$t = self::wt(__('Go forward to save button.', 'spambl_l10n'));
		printf('<p><a href="#aSubmit">%s</a></p>%s', $t, "\n");
		$t = self::wt(__('Go back to top (General section).', 'spambl_l10n'));
		printf('<p><a href="#general">%s</a></p>%s', $t, "\n");
	}


	// callback: put html install setting description
	public function put_inst_desc() {
		if ( self::get_verbose_option() !== 'true' ) {
			return;
		}

		$did = 'Spam_BLIP_Install_Desc';
		echo '<div id="' . $did . '">';

		$t = self::wt(__('Introduction:', 'spambl_l10n'));
		printf('<p><strong>%s</strong>%s</p>', $t, "\n");

		$t = self::wt(__('This section includes optional
			features for plugin install or uninstall. Currently,
			the only options are whether to remove the plugin\'s
			setup options and data storage from the 
			<em>WordPress</em> database when the plugin is deleted.
			There is probably no reason to leave the these data in
			place if you intend to delete the plugin permanently.
			If you intend to delete and then reinstall the plugin,
			possibly for a new version or update, then keeping the
			these data might be a good idea.', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		$t = self::wt(__('The "Delete setup options" option and the
			"Delete database table" option are independent;
			one may be deleted while the other is saved.
			', 'spambl_l10n'));
		printf('<p>%s</p>%s', $t, "\n");

		echo '</div>';
		?>
		<script type="text/javascript">
		addto_evhplg_obj_screenopt("verbose_show-hide", "<?php echo $did ?>");
		</script>
		<?php

		$t = self::wt(__('Go forward to save button.', 'spambl_l10n'));
		printf('<p><a href="#aSubmit">%s</a></p>%s', $t, "\n");
		$t = self::wt(__('Go back to top (General section).', 'spambl_l10n'));
		printf('<p><a href="#general">%s</a></p>%s', $t, "\n");
	}
	
	/**
	 * Options page fields callbacks
	 */
	
	// callback helper, put single checkbox
	public function put_single_checkbox($a, $opt, $label) {
		$group = self::opt_group;
		$c = $a[$opt] == 'true' ? "checked='CHECKED' " : "";

		echo "		<label><input type='checkbox' id='{$opt}' ";
		echo "name='{$group}[{$opt}]' value='true' {$c}/> ";
		echo "{$label}</label><br />\n";
	}
	
	// callback helper, put textarea pair w/ button pair
	// SEE calling code e.g., put_editrbl_opt($a), for $args
	public function put_textarea_pair($args)
	{
		//extract($args);
		// when this was 1st written WP core used extract() freely, but
		// it is now a function non grata: one named concern is
		// readability; obscure origin of vars seen in code, so readers:
		// the array elements in the explicit extraction below will
		// appear as variable names later.
		foreach(array(
			// textarea element attributes; esp., name
			'txattl',
			'txattr',
			// textarea initial values
			'txvall',
			'txvalr',
			// text area element labels
			'ltxlb',
			'rtxlb',
			// option (map) names as textarea IDs
			'ltxid',
			'rtxid',
			// incr for each, button IDs
			'lbtid',
			'rbtid',
			// button labels
			'lbttx',
			'rbttx',
			// incr for each, table element
			'tableid',
			// incr for each, debug span element
			'dbg_span',
			// JS control class name - a plugin class const
			'classname',
			// incr for each, up to 6, or add more in JS
			'obj_key') as $k) {
			$$k = isset($args[$k]) ? $args[$k] : '';
		}
	
		$jsarg = sprintf('"%s","%s","%s","%s","%s"',
			$ltxid, $rtxid, $lbtid, $rbtid, $dbg_span);
		// TODO: lose the align="" in the table below
	?>
	
		<table id="<?php echo $tableid; ?>"><tbody>
			<tr>
				<td align="left">
					<label for="<?php echo $ltxid; ?>"><?php echo $ltxlb; ?></label>						
				</td>
				<td align="left">
					<label for="<?php echo $rtxid; ?>"><?php echo $rtxlb; ?></label>						
				</td>
			</tr>
			<tr>
				<td align="right">
					<textarea id="<?php echo $ltxid; ?>" class="mceEditor" <?php echo $txattl; ?> ><?php echo $txvall; ?></textarea>
				</td>
				<td align="left">
					<textarea id="<?php echo $rtxid; ?>" class="mceEditor" <?php echo $txattr; ?> ><?php echo $txvalr; ?></textarea>
				</td>
			</tr>
			<tr>
				<td align="right">
					<input type="button" class="button" id="<?php echo $lbtid; ?>" value="<?php echo $lbttx; ?>" onclick="false;" />
				</td>
				<td align="left">
					<input type="button" class="button" id="<?php echo $rbtid; ?>" value="<?php echo $rbttx; ?>" onclick="false;" />
				</td>
			</tr>
		</tbody></table>
		<span id="<?php echo $dbg_span; ?>"></span>
		<script type="text/javascript">
			if ( <?php echo $obj_key; ?> === null ) {
				<?php echo $obj_key; ?> = new <?php echo $classname; ?>(<?php echo $jsarg; ?>);
			}
		</script>
	
	<?php
	}

	// callback, put verbose section introductions?
	public function put_verbose_opt($a) {
		$tt = self::wt(__('Show verbose introductions', 'spambl_l10n'));
		$k = self::optverbose;
		$this->put_single_checkbox($a, $k, $tt);

		// coopt this proc to put 'screen options' hidden opt:
		$group = self::opt_group;
		$eid = self::optscreen1;
		$enm = "{$group}[{$eid}]";
		$val = self::get_screen1_option() == 'true' ? "true" : "false";

		printf('<input id="%s" name="%s" value="%s" type="hidden">%s',
			$eid, $enm, $val, "\n"
		);
	}

	// callback, rbl filter comments?
	public function put_comments_opt($a) {
		$tt = self::wt(__('Check blacklist for comments', 'spambl_l10n'));
		$k = self::optcommflt;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, rbl filter pings?
	public function put_pings_opt($a) {
		$tt = self::wt(__('Check blacklist for pings', 'spambl_l10n'));
		$k = self::optpingflt;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, rbl filter user registration?
	public function put_regi_opt($a) {
		$tt = self::wt(__('Check blacklist for user registration', 'spambl_l10n'));
		$k = self::optregiflt;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, pass/whitelist TOR exit nodes?
	public function put_torpass_opt($a) {
		$tt = self::wt(__('Whitelist TOR addresses', 'spambl_l10n'));
		$k = self::opttorpass;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// store and use non-hit addresses to avoid addl. DNS lookups?
	public function put_nonhrec_opt($a) {
		$tt = self::wt(__('Store non-hit addresses for repeats', 'spambl_l10n'));
		$k = self::optnonhrec;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// check exising comments?
	public function put_chkexst_opt($a) {
		$tt = self::wt(__('Check address in existing comments', 'spambl_l10n'));
		$k = self::optchkexst;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// pass hits (do not reject)?
	public function put_rej_not_opt($a) {
		$tt = self::wt(__('Pass (do not reject) hits', 'spambl_l10n'));
		$k = self::optrej_not;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, rbl data store?
	public function put_recdata_opt($a) {
		$tt = self::wt(__('Store blacklist lookup results', 'spambl_l10n'));
		$k = self::optrecdata;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, use data store?
	public function put_usedata_opt($a) {
		$tt = self::wt(__('Use stored blacklist lookup results', 'spambl_l10n'));
		$k = self::optusedata;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, ttl data store
	public function put_ttldata_opt($a) {
		$tt = self::wt(__('Set "Time To Live" of database records', 'spambl_l10n'));
		$k = self::optttldata;
		$group = self::opt_group;
		$va = array(
			array(__('One hour, %s seconds', 'spambl_l10n'),
				''.(3600)),
			array(__('Six hours, %s seconds', 'spambl_l10n'),
				''.(3600*6)),
			array(__('Twelve hours, %s seconds', 'spambl_l10n'),
				''.(3600*12)),
			array(__('One day, %s seconds', 'spambl_l10n'),
				''.(3600*24)),
			array(__('One week, %s seconds', 'spambl_l10n'),
				''.(3600*24*7)),
			array(__('Two weeks, %s seconds', 'spambl_l10n'),
				''.(3600*24*7*2)),
			array(__('Four weeks, %s seconds', 'spambl_l10n'),
				''.(3600*24*7*4)),
			array(__('Set a value in seconds:', 'spambl_l10n'), ''.(0))
		);

		$v = trim('' . $a[$k]);
		$bhit = false;
		$txtval = ''.(3600*24*7*2);

		foreach ( $va as $oa ) {
			$txt = self::wt($oa[0]);
			$tim = $oa[1];
			$chk = '';
			if ( $tim !== '0' ) {
				$txt = sprintf($txt, $tim);
			}
			if ( $tim === '0' ) { // field entry
				if ( ! $bhit ) {
					$chk = 'checked="checked" ';
					$txtval = $v;
				}
			} else if ( $v === $tim ) { // radio val matched
				$bhit = true;
				$chk = 'checked="checked" ';
			}

			printf(
				"\n".'<label><input type="radio" id="%s" ', $k
			);
			printf(
				'name="%s[%s]" value="%s" %s/>', $group, $k, $tim, $chk
			);
			printf(
				'&nbsp;%s</label>%s'."\n", $txt,
				$tim === '0' ? '' : '<br/>'
			);
		}

		// text input associated with the last option radio button
		// note the "[${k}_text]" in the name attribute
		echo "&nbsp;&nbsp;&nbsp;<input id=\"{$k}\" name=\""
			. "{$group}[${k}_text]\" size=\"10\" type=\"text\""
			. " value=\"{$txtval}\" />\n\n";
	}

	// callback, ttl data store max records
	public function put_maxdata_opt($a) {
		$tt = self::wt(__('Set number of database records to keep', 'spambl_l10n'));
		$k = self::optmaxdata;
		$group = self::opt_group;
		$va = array(
			array(__('Ten (10)', 'spambl_l10n'), '10'),
			array(__('Fifty (50)', 'spambl_l10n'), '50'),
			array(__('One hundred (100)', 'spambl_l10n'), '100'),
			array(__('Two hundred (200)', 'spambl_l10n'), '200'),
			array(__('Five hundred (500)', 'spambl_l10n'), '500'),
			array(__('One thousand (1000)', 'spambl_l10n'), '1000'),
			array(__('Set a value:', 'spambl_l10n'), '0')
		);

		$v = trim('' . $a[$k]);
		$bhit = false;
		$txtval = '150';

		foreach ( $va as $oa ) {
			$txt = self::wt($oa[0]);
			$tim = $oa[1];
			$chk = '';
			if ( $tim === '0' ) { // field entry
				if ( ! $bhit ) {
					$chk = 'checked="checked" ';
					$txtval = $v;
				}
			} else if ( $v === $tim ) { // radio val matched
				$bhit = true;
				$chk = 'checked="checked" ';
			}

			printf(
				"\n".'<label><input type="radio" id="%s" ', $k
			);
			printf(
				'name="%s[%s]" value="%s" %s/>', $group, $k, $tim, $chk
			);
			printf(
				'&nbsp;%s</label>%s'."\n", $txt,
				$tim === '0' ? '' : '<br/>'
			);
		}

		// text input associated with the last option radio button
		// note the "[${k}_text]" in the name attribute
		echo "&nbsp;&nbsp;&nbsp;<input id=\"{$k}\" name=\""
			. "{$group}[${k}_text]\" size=\"10\" type=\"text\""
			. " value=\"{$txtval}\" />\n\n";
	}

	// callback, use plugin's widget?
	public function put_widget_opt($a) {
		$tt = self::wt(__('Enable the included widget', 'spambl_l10n'));
		$k = self::optplugwdg;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, log non-routable remate addrs?
	public function put_iplog_opt($a) {
		$tt = self::wt(__('Log bad addresses in "REMOTE_ADDR"', 'spambl_l10n'));
		$k = self::optipnglog;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, log blacklist hits?
	public function put_bliplog_opt($a) {
		$tt = self::wt(__('Log blacklist hits', 'spambl_l10n'));
		$k = self::optbliplog;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, die blacklist hits?
	public function put_bailout_opt($a) {
		$tt = self::wt(__('Bail (wp_die()) on blacklist hits', 'spambl_l10n'));
		$k = self::optbailout;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, put textarea pair for user whitelist
	public function put_editwhl_opt($a) {
		$gr = self::opt_group;
		$ol = self::opteditwhl;
		$or = self::opteditwhr;
		$dl = self::get_editwhl_option();
		$dr = self::get_editwhr_option();

		if ( $dl === '' || (! is_array($dl)) ) {
			$dl = array();
		}
		if ( $dr === '' || (! is_array($dr)) ) {
			$dr = array();
		}

		$vl = self::ht(implode("\n", $dl) . "\n");
		$vr = self::ht(implode("\n", $dr) . "\n");
	
		// sigh. ffox is so generous that its textareas are
		// much larger than others for the same dimension args
		$mozz = self::is_mozz();
		// Update: with WP 3.8 RC2 style is changed significantly, and
		// now text sizes are larger and textareas that were too small
		// in some (webkit?) browsers are now too large! Yet, FFox
		// remains about the same.
		// So, another boolean for that.
		$wp38 = self::wpv_min((3 << 24) | (8 << 16));

		// atts for textarea
		$txh = $mozz ? 12 : ($wp38 ? 12 : 12);
		$txw = $mozz ? 54 : ($wp38 ? 52 : 74);
		$txatt = sprintf('rows="%u" cols="%u"%s %s', $txh, $txw,
			$wp38 ? ' style="font-size: 85%;"' : "",
			'inputmode="verbatim" wrap="off"'
		);
	
		$aargs = array(
			// textarea element attributes; esp., name
			'txattl' => $txatt . ' placeholder="127.0.0.2" name="' . "{$gr}[{$ol}]" . '"',
			'txattr' => $txatt . ' placeholder="127.0.0.32" name="' . "{$gr}[{$or}]" . '"',
			// textarea initial values
			'txvall' => $vl,
			'txvalr' => $vr,
			// TRANSLATORS: these are labels above textarea elements
			// do not use html entities
			'ltxlb' => self::wt(__('Active User Whitelist:', 'spambl_l10n')),
			'rtxlb' => self::wt(__('Inactive User Whitelist:', 'spambl_l10n')),
			// option (map) names as textarea IDs
			'ltxid' => $ol,
			'rtxid' => $or,
			// incr for each, button IDs
			'lbtid' => 'evhplg_buttxpair_2_l',
			'rbtid' => 'evhplg_buttxpair_2_r',
			// TRANSLATORS: these are buttons below textarea elements,
			// effect is to move a line of text from one to the other;
			// '<<' and '>>' should suggest movement left and right
			// do not use html entities
			'lbttx' => self::wt(__('Move address right >>', 'spambl_l10n')),
			'rbttx' => self::wt(__('<< Move address left', 'spambl_l10n')),
			// incr for each, table element
			'tableid' => 'evhplg_tpair_table2',
			// incr for each, debug span element
			'dbg_span' => 'evhplg_debug_span2',
			// JS control class name - a plugin class const
			'classname' => self::js_textpair_ctl,
			// incr for each, up to 6, or add more in JS
			'obj_key' => self::js_textpair_ctl . '_objmap.form_2'
		);

		$this->put_textarea_pair($aargs);
	}

	// callback, put textarea pair for user blacklist
	public function put_editbll_opt($a) {
		$gr = self::opt_group;
		$ol = self::opteditbll;
		$or = self::opteditblr;
		$dl = self::get_editbll_option();
		$dr = self::get_editblr_option();

		if ( $dl === '' || (! is_array($dl)) ) {
			$dl = array();
		}
		if ( $dr === '' || (! is_array($dr)) ) {
			$dr = array();
		}

		$vl = self::ht(implode("\n", $dl) . "\n");
		$vr = self::ht(implode("\n", $dr) . "\n");
	
		// sigh. ffox is so generous that its textareas are
		// much larger than others for the same dimension args
		$mozz = self::is_mozz();
		// Update: with WP 3.8 RC2 style is changed significantly, and
		// now text sizes are larger and textareas that were too small
		// in some (webkit?) browsers are now too large! Yet, FFox
		// remains about the same.
		// So, another boolean for that.
		$wp38 = self::wpv_min((3 << 24) | (8 << 16));

		// atts for textarea
		$txh = $mozz ? 12 : ($wp38 ? 12 : 12);
		$txw = $mozz ? 54 : ($wp38 ? 52 : 74);
		$txatt = sprintf('rows="%u" cols="%u"%s %s', $txh, $txw,
			$wp38 ? ' style="font-size: 85%;"' : "",
			'inputmode="verbatim" wrap="off"'
		);

		$aargs = array(
			// textarea element attributes; esp., name
			'txattl' => $txatt . ' placeholder="127.0.0.2" name="' . "{$gr}[{$ol}]" . '"',
			'txattr' => $txatt . ' placeholder="10.0.0.0/8/255.0.0.0" name="' . "{$gr}[{$or}]" . '"',
			// textarea initial values
			'txvall' => $vl,
			'txvalr' => $vr,
			// TRANSLATORS: these are labels above textarea elements
			// do not use html entities
			'ltxlb' => self::wt(__('Active User Blacklist:', 'spambl_l10n')),
			'rtxlb' => self::wt(__('Inactive User Blacklist:', 'spambl_l10n')),
			// option (map) names as textarea IDs
			'ltxid' => $ol,
			'rtxid' => $or,
			// incr for each, button IDs
			'lbtid' => 'evhplg_buttxpair_3_l',
			'rbtid' => 'evhplg_buttxpair_3_r',
			// TRANSLATORS: these are buttons below textarea elements,
			// effect is to move a line of text from one to the other;
			// '<<' and '>>' should suggest movement left and right
			// do not use html entities
			'lbttx' => self::wt(__('Move address right >>', 'spambl_l10n')),
			'rbttx' => self::wt(__('<< Move address left', 'spambl_l10n')),
			// incr for each, table element
			'tableid' => 'evhplg_tpair_table3',
			// incr for each, debug span element
			'dbg_span' => 'evhplg_debug_span3',
			// JS control class name - a plugin class const
			'classname' => self::js_textpair_ctl,
			// incr for each, up to 6, or add more in JS
			'obj_key' => self::js_textpair_ctl . '_objmap.form_3'
		);

		$this->put_textarea_pair($aargs);
	}

	// callback, put textarea pair for RBL domains
	public function put_editrbl_opt($a) {
		$gr = self::opt_group;
		$ol = self::opteditrbl;
		$or = self::opteditrbr;
		$dl = self::get_editrbl_option();
		$dr = self::get_editrbr_option();

		if ( $dl === '' || (! is_array($dl)) ) {
			$dl = ChkBL_0_0_1::get_def_array();
		}
		if ( $dr === '' || (! is_array($dr)) ) {
			$dr = ChkBL_0_0_1::get_strict_array();
		}

		$t = array();
		foreach ( $dl as $a ) {
			$t[] = implode('@', $a);
		}
		$vl = self::ht(implode("\n", $t) . "\n");

		$t = array();
		foreach ( $dr as $a ) {
			$t[] = implode('@', $a);
		}
		$vr = self::ht(implode("\n", $t) . "\n");
	
		// sigh. ffox is so generous that its textareas are
		// much larger than others for the same dimension args
		$mozz = self::is_mozz();
		// Update: with WP 3.8 RC2 style is changed significantly, and
		// now text sizes are larger and textareas that were too small
		// in some (webkit?) browsers are now too large! Yet, FFox
		// remains about the same.
		// So, another boolean for that.
		$wp38 = self::wpv_min((3 << 24) | (8 << 16));

		// atts for textarea
		$txh = $mozz ? 7 : ($wp38 ? 9 : 7);
		$txw = $mozz ? 54 : ($wp38 ? 52 : 74);
		$txatt = sprintf('rows="%u" cols="%u"%s %s', $txh, $txw,
			$wp38 ? ' style="font-size: 85%;"' : "",
			'inputmode="verbatim" wrap="off"'
		);

		$aargs = array(
			// textarea element attributes; esp., name
			'txattl' => $txatt . ' placeholder="wanted.bl.example.net@127.0.0.2@0,=" name="' . "{$gr}[{$ol}]" . '"',
			'txattr' => $txatt . ' placeholder="not.wanted.bl.example.net@127.0.0.32@3,&amp;" name="' . "{$gr}[{$or}]" . '"',
			// textarea initial values
			'txvall' => $vl,
			'txvalr' => $vr,
			// TRANSLATORS: these are labels above textarea elements
			// do not use html entities
			'ltxlb' => self::wt(__('Active DNS Blacklists:', 'spambl_l10n')),
			'rtxlb' => self::wt(__('Inactive DNS Blacklists:', 'spambl_l10n')),
			// option (map) names as textarea IDs
			'ltxid' => $ol,
			'rtxid' => $or,
			// incr for each, button IDs
			'lbtid' => 'evhplg_buttxpair_1_l',
			'rbtid' => 'evhplg_buttxpair_1_r',
			// TRANSLATORS: these are buttons below textarea elements,
			// effect is to move a line of text from one to the other;
			// '<<' and '>>' should suggest movement left and right
			// do not use html entities
			'lbttx' => self::wt(__('Move line right >>', 'spambl_l10n')),
			'rbttx' => self::wt(__('<< Move line left', 'spambl_l10n')),
			// incr for each, table element
			'tableid' => 'evhplg_tpair_table1',
			// incr for each, debug span element
			'dbg_span' => 'evhplg_debug_span1',
			// JS control class name - a plugin class const
			'classname' => self::js_textpair_ctl,
			// incr for each, up to 6, or add more in JS
			'obj_key' => self::js_textpair_ctl . '_objmap.form_1'
		);

		$this->put_textarea_pair($aargs);
	}

	// callback, install section field: opt delete
	public function put_del_opts($a) {
		$tt = self::wt(__('Permanently delete plugin settings', 'spambl_l10n'));
		$k = self::optdelopts;
		$this->put_single_checkbox($a, $k, $tt);
	}

	// callback, install section field: data delete
	public function put_del_stor($a) {
		$tt = self::wt(__('Permanently delete database table (stored data)', 'spambl_l10n'));
		$k = self::optdelstor;
		$this->put_single_checkbox($a, $k, $tt);
	}

	/**
	 * WP options specific helpers
	 */

	// get the plugins main option group
	public static function get_opt_group() {
		return get_option(self::opt_group); /* WP get_option() */
	}
	
	// get an option value by name/key
	public static function opt_by_name($name) {
		$opts = self::get_opt_group();
		if ( $opts && array_key_exists($name, $opts) ) {
			return $opts[$name];
		}
		return null;
	}

	// for settings section introductions
	public static function get_verbose_option() {
		return self::opt_by_name(self::optverbose);
	}

	// for settings section 'screen options'; hidden input value
	public static function get_screen1_option() {
		return self::opt_by_name(self::optscreen1);
	}

	// for whether to use widget
	public static function get_widget_option() {
		return self::opt_by_name(self::optplugwdg);
	}

	// for whether to log reserved remote addresses
	public static function get_ip_log_option() {
		return self::opt_by_name(self::optipnglog);
	}

	// for whether to log BL hits
	public static function get_hitlog_option() {
		return self::opt_by_name(self::optbliplog);
	}

	// for whether to die on BL hits
	public static function get_bailout_option() {
		return self::opt_by_name(self::optbailout);
	}

	// for whether to store hit data
	public static function get_recdata_option() {
		return self::userecdata_enable
			? self::opt_by_name(self::optrecdata)
			: 'true';
	}

	// for whether to use stored data
	public static function get_usedata_option() {
		return self::userecdata_enable
			? self::opt_by_name(self::optusedata)
			: 'true';
	}

	// ttl of stored data; seconds (time)
	public static function get_ttldata_option() {
		return self::opt_by_name(self::optttldata);
	}

	// max number of stored data
	public static function get_maxdata_option() {
		return self::opt_by_name(self::optmaxdata);
	}

	// should the filter_comments_open() rbl check be done
	public static function get_comments_open_option() {
		return self::opt_by_name(self::optcommflt);
	}

	// should the filter_pings_open() rbl check be done
	public static function get_pings_open_option() {
		return self::opt_by_name(self::optpingflt);
	}

	// should the action_user_regi() rbl check be done
	public static function get_user_regi_option() {
		return self::opt_by_name(self::optregiflt);
	}

	// for whether to pass/whitelist tor exit nodes
	public static function get_torwhite_option() {
		return self::opt_by_name(self::opttorpass);
	}

	// for whether to store non-hit lookups
	public static function get_rec_non_option() {
		return self::opt_by_name(self::optnonhrec);
	}

	// for whether to check WP stored comments
	public static function get_chkexist_option() {
		return self::opt_by_name(self::optchkexst);
	}

	// don't reject, but pass hits
	public static function get_rej_not_option() {
		return self::opt_by_name(self::optrej_not);
	}

	// get active RBL domains
	public static function get_editrbl_option() {
		return self::opt_by_name(self::opteditrbl);
	}

	// get inactive RBL domains
	public static function get_editrbr_option() {
		return self::opt_by_name(self::opteditrbr);
	}

	// get active 	user whitelist
	public static function get_editwhl_option() {
		return self::opt_by_name(self::opteditwhl);
	}

	// get inactive user whitelist
	public static function get_editwhr_option() {
		return self::opt_by_name(self::opteditwhr);
	}

	// get active 	user blacklist
	public static function get_editbll_option() {
		return self::opt_by_name(self::opteditbll);
	}

	// get inactive user blacklist
	public static function get_editblr_option() {
		return self::opt_by_name(self::opteditblr);
	}

	/**
	 * core functionality
	 */

	public function bl_check_addr($addr) {
		if ( $this->chkbl === null ) {
			$da = self::get_editrbl_option();
			if ( $da == '' || (! is_array($da)) || empty($da) ) {
				$da = ChkBL_0_0_1::get_def_array();
			}
			$err = array(__CLASS__, 'errlog');
			$this->chkbl = new ChkBL_0_0_1($da, false, $err);
		}
		
		if ( ! $this->chkbl ) {
			self::errlog(__('cannot allocate BL check object', 'spambl_l10n'));
			return false;
		}
		
		$ret = false;
		// The simple check is not needed here
		if ( true ) {
			$this->rbl_result = $this->chkbl->check_all($addr, 1);
			if ( ! empty($this->rbl_result) ) {
				$ret = $this->rbl_result[0][2];
			} else {
				// place false in empty array
				$this->rbl_result[] = false;
			}
		} else {
			// in ctor $rbl_result is assigned false, so if
			// other code finds it false, this code has not
			// been reached
			$this->rbl_result = array();
			$ret = $this->chkbl->check_simple($addr);
			// DEVEL: remove
			if ( false && $addr === '192.168.1.187' ) {
				$ret = true;
			}
			// simple case: put result in [0]
			$this->rbl_result[] = $ret;
		}
		
		return $ret;
	}

	// helper: get previous BL result, return:
	// null if no previous result, else the
	// boolean result (true||false)
	public function get_rbl_result() {
		if ( ! is_array($this->rbl_result) ) {
			return null;
		}
		if ( is_array($this->rbl_result[0]) ) {
			return $this->rbl_result[0][2];
		}
		return $this->rbl_result[0];
	}

	// helper: get internal, non-BL result, return:
	// null if no previous result, else the
	// boolean result (true||false)
	public function get_dbl_result() {
		if ( ! is_array($this->dbl_result) ) {
			return null;
		}
		return $this->dbl_result[0];
	}

	// anything scheduled for just before PHP shutdow: WP
	// calls this action from its own registered
	// PHP register_shutdown_function() callback
	public function action_shutdown() {
		if ( $this->do_db_maintain ) {
			$this->do_db_maintain = false;
			$this->db_tbl_real_maintain();
		}
	}

	// action called from cron hook; 
	public static function action_static_cron($what) {
		if ( is_array($what) ) {
			$what = $what[0];
		}
		switch ( $what ) {
			case self::maint_intvl:
				$inst = self::get_instance(); // can call repeatedly
				$inst->db_tbl_maintain();
				break;
			default:
				break;
		}
	}

	// add_action('pre_comment_on_post', $scf, 1);
	// This action is called from the last 'else' in
	// and if/else chain starting with a test of comments_open()
	// which applies filter 'comments_open', see filter_comments_open()
	// below. If comments are open, this gets called. DNS RBL
	// lookup is done here because the wait for result will
	// only affect the commenter, not every page load. A real
	// human commenter will probably not find a delay of a couple
	// seconds after submitting comment as noticeable as a
	// similar delay in the whole page load; it will just
	// seem like processing of comment at server (which it is).
	// This does not get called if post status is 'trash' or
	// if it is a draft or requires password -- all those cause
	// an exit (after an action hook call), so spam should
	// not get through in those cases.
	public function action_pre_comment_on_post($comment_post_ID) {
		if ( self::get_comments_open_option() != 'true' ) {
			return;
		}		

		self::dbglog('enter ' . __FUNCTION__);

		// was rbl check called already? if so,
		// use stored result
		$prev = $this->get_rbl_result();
		
		// if not done already
		if ( $prev === null ) {
			$this->do_db_bl_check(true, 'comments') ;
			$prev = $this->get_rbl_result();
		}
		
		if ( $prev !== false ) {
			if ( self::get_rej_not_option() == 'true' ) {
				self::dbglog('no-reject option, in ' . __FUNCTION__);
				return;
			}
			
			self::dbglog('BAILING FROM ' . __FUNCTION__);
			// TRANSLATORS: polite rejection message
			// in response to blacklisted IP address
			wp_die(__('Sorry, but no, thank you.', 'spambl_l10n'));
		}
	}

	// this action is invoked in wp-trackback.php, last action
	// before trackback_response(0); just after
	// wp_new_comment($commentdata), so it is too late
	// to prevent spam -- *but* pings_open() is called earlier
	// in the block of code, and that is filtered, so it's OK.
	//public function action_trackback_post($insert_ID) {
		//if ( self::get_pings_open_option() != 'true' ) {
			//return;
		//}		

		//self::dbglog('enter ' . __FUNCTION__);

		//// was rbl check called already? if so,
		//// use stored result
		//$prev = $this->get_rbl_result();
		
		//// if not done already
		//if ( $prev === null ) {
			//$this->do_db_bl_check(true, 'pings') ;
			//$prev = $this->get_rbl_result();
		//}
		
		//if ( $prev !== false ) {
			//if ( self::get_rej_not_option() == 'true' ) {
				//self::dbglog('no-reject option, in ' . __FUNCTION__);
				//return;
			//}
			
			//self::dbglog('BAILING FROM ' . __FUNCTION__);
			//// TRANSLATORS: polite rejection message
			//// in response to blacklisted IP address
			//wp_die(__('Sorry, but no, thank you.', 'spambl_l10n'));
		//}
	//}

	// add_action('comment_closed', $scf, 1);
	// This gets called if comments_open(), filtered below,
	// yields not true. The block ends with wp_die(), so it is
	// not needed here, and would exclude subsequent hooks if
	// done here. An additional message might be printed, even
	// though there's not much point to it
	public function action_comment_closed($comment_post_ID) {
		if ( self::get_comments_open_option() != 'true' ) {
			return;
		}
		
		if ( self::get_rej_not_option() == 'true' ) {
			return;
		}
		
		if ( $this->get_dbl_result() === true ) {
			// TRANSLATORS: polite rejection message
			// in response to blacklisted IP address
			echo __('Sorry, but no, thank you.', 'spambl_l10n') .'<hr>';
		}
	}

	// add_action('login_form_' . 'register', $scf, 1) -- wp-login.php;
	// This gets called if on new user registration, and a site
	// may optionally require registration to comment, so this is
	// pertinent to comment spam.
	public function action_user_regi() {
		if ( self::get_user_regi_option() != 'true' ) {
			return;
		}

		if ( ! self::check_filter_user_regi() ) {
			return;
		}

		self::dbglog('enter ' . __FUNCTION__);

		// was rbl check called already? if so,
		// use stored result
		$prev = $this->get_rbl_result();
		
		// if not done already
		if ( $prev === null ) {
			$this->do_db_bl_check(true, 'comments') ;
			$prev = $this->get_rbl_result();
		}
		
		if ( $prev !== false ) {
			if ( self::get_rej_not_option() == 'true' ) {
				self::dbglog('no-reject option, in ' . __FUNCTION__);
				return;
			}
			
			self::dbglog('BAILING FROM ' . __FUNCTION__);
			// TRANSLATORS: polite rejection message
			// in response to blacklisted IP address
			wp_die(__('Sorry, but no, thank you.', 'spambl_l10n'));
		}
	}

	// add_filter('register', $scf, 1);
	// NOTE: called for each register-link that may display.
	// This should not be used for the DNS RBL lookup because
	// waiting for the response can caused a noticeable stall
	// of page loading in client.
	// action_user_regi is used for the RBL lookup;
	// see comment there.
	// OTOH, this filter will look in the hit db, which is fast,
	// and nip it in the bud early if a hit is found.
	public function filter_user_regi($link) {
		if ( self::get_user_regi_option() != 'true' ) {
			return $link;
		}

		if ( ! self::check_filter_user_regi() ) {
			return $link;
		}

		self::dbglog('enter ' . __FUNCTION__);

		// was rbl check called already? if so,
		// use stored result
		$prev = $this->get_dbl_result();
		
		// if not done already
		if ( $prev === null ) {
			// false limits check: no DNS
			$this->do_db_bl_check(true, 'comments', false) ;
			$prev = $this->get_dbl_result();
		}
		
		// if already done, but not a hit
		if (  $prev === false ) {
			return $link;
		}

		// already got a hit on this IP addr
		// DO NOT be put alternate content in $link: the caller
		// prepends and appends elements such as '<li>', and replacing
		// those might have unpleasant results -- OTOH, the caller
		// also conditionally uses the empty string, so it may
		// be considered an appropriate return
		if ( self::get_rej_not_option() == 'true' ) {
			self::dbglog('no-reject option, in ' . __FUNCTION__);
			return $link;
		}		
		$link = '';
		return $link;
	}

	// add_filter('comments_open', $scf, 1);
	// NOTE: this may/will be called many times per page,
	// for each comment link on page.
	// This should not be used for the DNS RBL lookup because
	// waiting for the response can caused a noticeable stall
	// of page loading in client.
	// action_pre_comment_on_post is used for the RBL lookup;
	// see comment there.
	// OTOH, this filter will look in the hit db, which is fast,
	// and nip it in the bud early if a hit is found.
	public function filter_comments_open($open) {
		if ( self::get_comments_open_option() != 'true' ) {
			return $open;
		}		

		// was data store check called already? if so,
		// use stored result
		$prev = $this->get_dbl_result();
		
		// if not done already
		if ( $prev === null ) {
			// false limits check: no DNS
			return $this->do_db_bl_check($open, 'comments', false);
		}
		
		// if already done, but not a hit
		if (  $prev === false ) {
			return $open;
		}

		// already got a hit on this IP addr		
		if ( self::get_rej_not_option() == 'true' ) {
			self::dbglog('no-reject option, in ' . __FUNCTION__);
			return $open;
		}		
		return false;
	}

	// add_filter('pings_open', $scf, 1);
	public function filter_pings_open($open) {
		if ( self::get_pings_open_option() != 'true' ) {
			return $open;
		}		

		// was rbl check called already? if so,
		// use stored result
		$prev = $this->get_rbl_result();
		
		// if not done already
		if ( $prev === null ) {
			return $this->do_db_bl_check($open, 'pings');
		}
		
		// if already done, but not a hit
		if ( $prev === false ) {
			return $open;
		}

		// already got a hit on this IP addr		
		if ( self::get_rej_not_option() == 'true' ) {
			self::dbglog('no-reject option, in ' . __FUNCTION__);
			return $open;
		}		
		return false;
	}

	// internal BL check for use by e.g., filters
	// Returns false for a BL hit, else returns arg $def
	public function do_db_bl_check($def, $statype, $rbl = true) {
		// 1st, get address, check if it is useable
		$addr = self::get_conn_addr();
		if ( $addr === false ) {
			$addr = $_SERVER["REMOTE_ADDR"];
			$fmt = self::check_ip6_address($addr) ?
				__('Got IP version 6 address "%s"; sorry, only IP4 handled currently', 'spambl_l10n')
				:
				__('Invalid remote address; "REMOTE_ADDR" contains "%s"', 'spambl_l10n');
			self::errlog(sprintf($fmt, $addr));
			return $def;
		}
		
		$pretime = self::best_time();

		// optional check in user whitelist
		// *before* reserved address check, to allow
		// whitelisting those
		if ( $this->chk_user_whitelist($addr, $statype, $pretime) ) {
			// set the result; checked in various places
			$this->rbl_result = array(false);
			// flag this like db check w a hit
			$this->dbl_result = array(false);
			return $def;
		}

		// Check for not non-routable CGI/1.1 envar REMOTE_ADDR
		// as can actually happen with some hosting hacks.
		$ret = $this->ipchk_done ? false
			: $this->ipchk->chk_resv_addr($addr);
		$this->ipchk_done = true;
		if ( $ret !== false ) {
			if ( self::get_ip_log_option() != 'false' ) {
				// TRANSLATORS: word for ietf/iana reserved network
				$rsz = __('RESERVED', 'spambl_l10n');
				// TRANSLATORS: word for ietf/iana loopback network
				$lpb = __('LOOPBACK', 'spambl_l10n');
				$ret = $ret ? $rsz : $lpb;
				// TRANSLATORS: %1$s is either "RESERVED" or "LOOPBACK";
				// see comments above.
				// %2$s is an IPv4 dotted quad address
				$fmt = __('Got %1$s IPv4 address "%2$s" in "REMOTE_ADDR".', 'spambl_l10n');
				$ret = sprintf($fmt, $ret, $addr);
				self::errlog($ret);
				// TODO: email admin (well, probably not)
				$this->handle_REMOTE_ADDR_error($ret);
			}
			// can't continue; set result false
			$this->rbl_result = array(false);
			$this->dbl_result = array(false);
			return $def;
		}

		$ret = false; // redundant, safe

		// optional check in user blacklist
		if ( ! $ret &&
			$this->chk_user_blacklist($addr, $statype, $pretime) ) {
			$ret = true;
		}

		// optional check in WP stored comments
		if ( ! $ret &&
			$this->chk_comments($addr, $statype, (int)$pretime) ) {
			$ret = true;
		}

		// option to whitelist addresses that TOR lists as exit nodes
		// or that have been previously checked and were not hits (non)
		// this is allowed a pass only if the comment check above
		// did not hit; this way an addr whiltelisted here previously
		// is not let past again if user marked the comment as spam --
		// also the check above changes the 'lasttype' in our table
		// to the $stattype arg ('comments' or 'pings')
		if ( ! $ret &&
			$this->tor_nonhit_opt_whitelist($addr, $rbl) ) {
			// set the result; checked in various places
			$this->rbl_result = array(false);
			// flag this like db check w/o a hit
			$this->dbl_result = array(false);
			return $def;
		}
		
		// optional data store check
		if ( ! $ret &&
			$this->chk_db_4_hit($addr, $statype, $pretime) ) {
			$ret = true;
		}

		// got hit above?
		if ( $ret ) {
			// set the result; checked in various places
			$this->rbl_result = array(true);
			// flag this like db check w a hit
			$this->dbl_result = array(true);
			// optionally die
			self::hit_optional_bailout($addr, $statype);
			// Just say NO! (to Nancy)
			return false;
		}

		// if $rbl not true, only the checks above are
		// wanted, for calls that should not wait on DNS
		if ( $rbl !== true ) {
			$this->dbl_result = array(false);
			return $def;
		}

		// time again, in case stuff above was slow,
		// and do lookup
		$pretime = self::best_time();
		$ret = $this->bl_check_addr($addr);
		$posttime = self::best_time();
		self::dbglog(
			'DNS lookup in ' . ($posttime - $pretime) . ' secs');

		if ( $ret === false ) {
			// not a RBL hit
			if ( self::get_rec_non_option() != 'false' &&
			     self::get_recdata_option() != 'false' ) {
				$this->db_update_array(
					$this->db_make_array(
						$addr, 1, (int)$pretime, 'non'
					)
				);
			}
			return $def;
		}
		
		// We have a hit!
		$ret = false;
		
		// optionally record stats
		if ( self::get_recdata_option() != 'false' ) {
			$this->db_update_array(
				$this->db_make_array(
					$addr, 1, (int)$pretime, $statype
				)
			);
		}

		// optional hit logging
		if ( self::get_hitlog_option() != 'false' ) {
			$difftime = $posttime - $pretime;
			// TRANSLATORS: see "TRANSLATORS: %1$s is type..."
			$ptxt = __('pings', 'spambl_l10n');
			// TRANSLATORS: see "TRANSLATORS: %1$s is type..."
			$ctxt = __('comments', 'spambl_l10n');

			$dtxt = $statype === 'pings' ? $ptxt :
				($statype === 'comments' ? $ctxt : $statype);

			if ( is_array($this->rbl_result[0]) ) {
				$doms = $this->chkbl->get_dom_array();
				$fmt =
					// TRANSLATORS: %1$s is type "comments" or "pings"
					// %2$s is IP4 address dotted quad
					// %3$s is DNS blacklist lookup domain
					// %4$s is IP4 blacklist lookup result
					// %5$f is lookup time in seconds (float)
					__('%1$s denied for address %2$s, list at "%3$s", result %4$s in %5$f', 'spambl_l10n');
				$fmt = sprintf($fmt, $dtxt, $addr,
					$doms[ $this->rbl_result[0][0] ][0],
					$this->rbl_result[0][1], $difftime);
				self::errlog($fmt);
			} else {
				$fmt =
					// TRANSLATORS: %1$s is type "comments" or "pings"
					// %2$s is IP4 address dotted quad
					// %3$f is lookup time in seconds (float)
					__('%1$s denied for address %2$s in %3$f', 'spambl_l10n');
				$fmt = sprintf($fmt, $dtxt, $addr, $difftime);
				self::errlog($fmt);
			}
		}		
		
		// optionally die
		self::hit_optional_bailout($addr, $statype);

		return $ret;
	}


	// optionally user whitelist for address
	protected function chk_user_whitelist($addr, $statype, $tm) {
		$l = self::get_editwhl_option();
		if ( $l === '' || ! is_array($l) || count($l) < 1 ) {
			return false;
		}

		// find address, or as of 1.0.2 network, match
		// see comment in validate()
		$hit = false;
		foreach ( $l as $v ) {
			$a = explode('/', $v);
			if ( is_array($a) && count($a) > 1 ) {
				// when entered on the settings page,
				// validation code made string clean and normal,
				// so array contents should be as expected
				$n = $a[0]; // net addr
				$m = $a[1]; // net mask
				$hit = NetMisc_0_0_1::is_addr_in_net($addr, $n, $m);
			} else {
				$hit = ($addr === $v) ? true : false;
			}
			if ( $hit !== false ) {
				break;
			}
		}
		if ( $hit === false ) {
			return false;
		}

		// optional hit logging
		if ( self::get_hitlog_option() != 'false' ) {
			// TRANSLATORS: see "TRANSLATORS: %1$s is type..."
			$ptxt = __('pings', 'spambl_l10n');
			// TRANSLATORS: see "TRANSLATORS: %1$s is type..."
			$ctxt = __('comments', 'spambl_l10n');

			$dtxt = $statype === 'pings' ? $ptxt :
				($statype === 'comments' ? $ctxt : $statype);

			$fmt =
			// TRANSLATORS: %1$s is type "comments" or "pings"
			// %2$s is IP4 address dotted quad
			// %3$f is is time (float) used in option check
			__('%1$s allowed address %2$s, found in user whitelist (lookup time %3$f)', 'spambl_l10n');
			$fmt = sprintf($fmt, $dtxt, $addr, self::best_time() - $tm);
			self::errlog($fmt);
		}		

		// optionally record stats
		if ( self::get_recdata_option() != 'false' ) {
			$this->db_update_array(
				$this->db_make_array(
					$addr, 1, (int)$tm, 'white'
				)
			);
		}
		
		return true;
	}

	// optionally user blacklist for address
	protected function chk_user_blacklist($addr, $statype, $tm) {
		$l = self::get_editbll_option();
		if ( $l === '' || ! is_array($l) || count($l) < 1 ) {
			return false;
		}

		// find address, or as of 1.0.2 network, match
		// see comment in validate()
		$hit = false;
		foreach ( $l as $v ) {
			$a = explode('/', $v);
			if ( is_array($a) && count($a) > 1 ) {
				// when entered on the settings page,
				// validation code made string clean and normal,
				// so array contents should be as expected
				$n = $a[0]; // net addr
				$m = $a[1]; // net mask
				$hit = NetMisc_0_0_1::is_addr_in_net($addr, $n, $m);
			} else {
				$hit = ($addr === $v) ? true : false;
			}
			if ( $hit !== false ) {
				break;
			}
		}
		if ( $hit === false ) {
			return false;
		}

		// optional hit logging
		if ( self::get_hitlog_option() != 'false' ) {
			// TRANSLATORS: see "TRANSLATORS: %1$s is type..."
			$ptxt = __('pings', 'spambl_l10n');
			// TRANSLATORS: see "TRANSLATORS: %1$s is type..."
			$ctxt = __('comments', 'spambl_l10n');

			$dtxt = $statype === 'pings' ? $ptxt :
				($statype === 'comments' ? $ctxt : $statype);

			$fmt =
			// TRANSLATORS: %1$s is type "comments" or "pings"
			// %2$s is IP4 address dotted quad
			// %3$f is is time (float) used in option check
			__('%1$s denied address %2$s, found in user blacklist (lookup time %3$f)', 'spambl_l10n');
			$fmt = sprintf($fmt, $dtxt, $addr, self::best_time() - $tm);
			self::errlog($fmt);
		}		

		// optionally record stats
		if ( self::get_recdata_option() != 'false' ) {
			$this->db_update_array(
				$this->db_make_array(
					$addr, 1, (int)$tm, 'black'
				)
			);
		}
		
		return true;
	}

	// optionally check data store for address
	protected function chk_db_4_hit($addr, $statype, $tm = null) {
		if ( self::get_usedata_option() == 'false' ) {
			return false;
		}

		$pretime = $tm ? $tm : self::best_time();
		$d = $this->db_get_address($addr);
		$posttime = self::best_time();
	
		$hit = false;
		if ( is_array($d) ) {
			if ( $d['lasttype'] === 'comments' ||
				 $d['lasttype'] === 'pings' ) {
				$hit = true;
			}
		}

		// got it?
		if ( $hit === false ) {
			return false;
		}

		// optional hit logging
		if ( self::get_hitlog_option() != 'false' ) {
			// TRANSLATORS: see "TRANSLATORS: %1$s is type..."
			$ptxt = __('pings', 'spambl_l10n');
			// TRANSLATORS: see "TRANSLATORS: %1$s is type..."
			$ctxt = __('comments', 'spambl_l10n');

			$dtxt = $statype === 'pings' ? $ptxt :
				($statype === 'comments' ? $ctxt : $statype);

			$fmt =
			// TRANSLATORS: %1$s is type "comments" or "pings"
			// %2$s is IP4 address dotted quad
			// %3$s is first seen date; in UTC, formatted
			//      in *site host* machine's locale
			// %4$s is last seen date; as above
			// %5$u is integer number of times seen (hitcount)
			// %6$f is is time (float) used in database check
			_n('%1$s denied for address %2$s, first seen %3$s, last seen %4$s, previously seen %5$u time; (db time %6$f)',
			   '%1$s denied for address %2$s, first seen %3$s, last seen %4$s, previously seen %5$u times; (db time %6$f)',
			   (int)$d['hitcount'], 'spambl_l10n');
			$fmt = sprintf($fmt, $dtxt, $addr,
				gmdate(DATE_RFC2822, (int)$d['seeninit']),
				gmdate(DATE_RFC2822, (int)$d['seenlast']),
				(int)$d['hitcount'], $posttime - $pretime);
			self::errlog($fmt);
		}		

		// optionally record stats
		if ( self::get_recdata_option() != 'false' ) {
			$this->db_update_array(
				$this->db_make_array(
					$addr, 1, (int)$pretime, $statype
				)
			);
		}
		
		return true;
	}

	// optionally check comments saved by WP for those marked
	// as spam and having address $addr and having GMT >
	// $tm - TTL option
	protected function chk_comments($addr, $type, $tm) {
		$opt = self::get_chkexist_option();
		if ( $opt == 'false' ) {
			return false;
		}

		global $wpdb;

		$ttl = (int)self::get_ttldata_option();
		if ( $ttl < 1 ) {
			$ttl = (int)$tm;
		}
		$old = (int)$tm - $ttl;

		// this format works with $wpdb->comments up to WP 3.6
		$f = "%s FROM %s WHERE %s = '%s' AND %s = '%s' AND %s > %u";
		$q = sprintf($f, 
			'SELECT COUNT(*)', $wpdb->comments,
			'comment_approved', 'spam',
			'comment_author_IP', $addr,
			'UNIX_TIMESTAMP(comment_date_gmt)', $old
		);
		$r = $wpdb->get_results($q, ARRAY_A);

		if ( is_array($r) && (int)$r[0]['COUNT(*)'] > 0 ) {
			if ( self::get_recdata_option() != 'false' ) {
				$this->db_update_array(
					$this->db_make_array(
						$addr, 1, (int)$tm, $type
					)
				);
			}
			self::dbglog('FOUND spam comment (' .
				$r[0]['COUNT(*)'] . '), address ' . $addr);

			return true;
		}

		return false;
	}

	// if option to whitelist TOR is set and address is *found*
	// to be a TOR exit node (there are false negatives), then
	// return true; else return false
	// Also, coopt this proc for the optional recording/checking
	// of non-hits
	protected function tor_nonhit_opt_whitelist($addr, $dns = true) {
		// if opt
		$toro = self::get_torwhite_option();
		$nono = self::get_rec_non_option();
		if ( $toro == 'false' && $nono == 'false' ) {
			return false;
		}

		$t = '';
		if ( self::get_usedata_option() != 'false' ) {
			$d = $this->db_get_address($addr);
			if ( is_array($d) ) {
				$t = $d['lasttype'];
			}

			if ( $t === 'non' ) {
				if ( $nono == 'false' ) $t = '';
			} else if ( $t === 'torx' ) {
				if ( $toro == 'false' ) $t = '';
			}

			if ( $t === 'torx' ) {
				if ( self::get_hitlog_option() != 'false' ) {
					// TRANSLATORS: %1$s is IP4 address; %2$u is the
					// number of times adress was seen previously
					$m = __('Found "%1$s" to be a tor exit, %2$u hits in data -- passed per option', 'spambl_l10n');
					self::errlog(sprintf($m, $addr, $d['hitcount']));
				}
			}
			if ( $t === 'torx' || $t === 'non' ) {
				// optionally record stats
				if ( self::get_recdata_option() != 'false' ) {
					$this->db_update_array(
						$this->db_make_array(
							$addr, 1, (int)time(), $t
						)
					);
				}
			
				return true;
			}
		}

		// remainder is only for tor, and only if DNS check is wanted
		if ( $toro == 'false' || $dns !== true ) {
			return false;
		}

		$s = $_SERVER["SERVER_ADDR"];
		if ( $this->ipchk->chk_resv_addr($s) ) {
			// broken proxy/cache/frontend in shared hosting?
			// hopefully this DNS query will return with success
			// very quickly, as the domain should be handled
			// on this net or close to it; note the lack of
			// trailing dot, too
			$s = gethostbyname($_SERVER["SERVER_NAME"]);
			// test PHP's peculiar error return
			if ( $s == $_SERVER["SERVER_NAME"] ) {
				$s = false;
			}
		}
		if ( $s && ChkBL_0_0_1::chk_tor_exit($addr, $s) ) {
			if ( self::get_hitlog_option() != 'false' ) {
				// TRANSLATORS: %s is IP4 address; DNS is the
				// domain name system
				$m = __('Found "%s" to be a tor exit, by DNS -- passed per option', 'spambl_l10n');
				self::errlog(sprintf($m, $addr));
			}
			// optionally record stats
			if ( self::get_recdata_option() != 'false' ) {
				// use 'torx' for tor exit node
				$this->db_update_array(
					$this->db_make_array(
						$addr, 1, (int)time(), 'torx'
					)
				);
			}
			
			return true;
		}
		
		return false;
	}

	protected static function hit_optional_bailout($addr, $statype) {
		if ( self::get_rej_not_option() == 'true' ) {
			return;
		}

		if ( self::get_bailout_option() != 'false' ) {
			// Allow additional action from elsewhere, however unlikely.
			do_action('spamblip_hit_bailout', $addr, $statype);
			// TODO: make message text an option
			wp_die(__('Sorry, but no, thank you.', 'spambl_l10n'));
		}
	}

	/**
	 * methods for optional data store
	 */
	 
	// get db table name
	protected function db_tablename() {
		global $wpdb;
		
		// const data_suffix
		if ( $this->data_table === null ) {
			$this->data_table = $wpdb->prefix . self::data_suffix;
		}
		
		return $this->data_table;
	}
	
	// Probably lack privilege for "LOCK TABLES",
	// so use this advisory form; unlocking is less critical,
	// but of course still should not be forgotten (server
	// removes a lock when connection is closed, say docs).
	// Added 1.0.4: $tmo: timeout arg (was a hardcoded 10);
	// default is long to cover varied net symptoms, plus
	// $type arg removed as it was only an artifact
	protected function db_lock_table($tmo = 45) {
		global $wpdb;
		$tbl = $this->db_tablename();
		$lck = 'lck_' . $tbl;
		$qs = sprintf("SELECT GET_LOCK('%s',%u);", $lck, (int)$tmo);
		$r = $wpdb->get_results($qs, ARRAY_N);
		if ( is_array($r) && is_array($r[0]) ) {
			return (int)$r[0][0];
		}
		self::errlog("FAILED get lock query " . $qs);
		return false;
	}
	
	// unlock locked table: DO NOT FORGET
	protected function db_unlock_table() {
		global $wpdb;
		$tbl = $this->db_tablename();
		$lck = 'lck_' . $tbl;
		$qs = sprintf("SELECT RELEASE_LOCK('%s');", $lck);
		$r = $wpdb->get_results($qs, ARRAY_N);
		if ( is_array($r) && is_array($r[0]) ) {
			return (int)$r[0][0];
		}
		self::errlog("FAILED release lock query " . $qs);
		return false;
	}
	
	// maintain table: just set flag; act on shutdown hook
	protected function db_tbl_maintain() {
		// flag maintenance at shutdown
		if ( self::get_recdata_option() != 'false' ||
			 self::get_usedata_option() != 'false' ) {
			$this->do_db_maintain = true;
		}
	}
	
	// maintain table: trim according to TTL and max rows options
	private function db_tbl_real_maintain() {
		$tm = self::best_time();
		global $wpdb;
		
		$r1 = $r2 = false;
		//$wpdb->show_errors();
		$c = self::get_ttldata_option();
		// 0 (or less) disables
		if ( (int)$c >= 1 ) {
			$c = (int)time() - (int)$c;
			if ( $c > 0 ) {
				$f = $r1 = $this->db_remove_older_than($c);
				if ( $f === false ) $f = 'false';
				self::dbglog('GOT from db_remove_older_than: ' . $f);
			}
		}

		$c = self::get_maxdata_option();
		// 0 (or less) disables
		if ( (int)$c >= 1 ) {
			$f = $r2 = $this->db_remove_above_max($c);
			if ( $f === false ) $f = 'false';
			self::dbglog('GOT from db_remove_above_max: ' . $f);
		}
		
		// if records were removed ...
		if ( $r1 || $r2 ) {
			// ... optimize
			$this->db_optimize();
		}
		//$wpdb->hide_errors();

		$tm = self::best_time() - $tm;
		self::dbglog('table maintenance in ' . $tm . ' seconds');
	}

	// do optimize if free percent too great,
	// or optional analyze
	protected function db_optimize($analyze = true) {
		global $wpdb;
		$tbl = $this->db_tablename();
		$db = DB_NAME;
		$fpct = 0;
		$len = 0;

		$this->db_lock_table();
		
		$r = $wpdb->get_results(
			"SELECT data_length, data_free "
			. "FROM information_schema.TABLES "
			. "where TABLE_SCHEMA = '{$db}' "
				. "AND TABLE_NAME = '{$tbl}';",
			ARRAY_N
		);

		if ( is_array($r) && isset($r[0]) && isset($r[0][0]) ) {
			$len = (int)$r[0][0];
			$free = (int)$r[0][1];
			if ( $len > 0 ) {
				$fpct = ($free * 100) / $len;
			}
		}
		
		// TODO: make an option
		$fragmax = 15;
		// TODO: tune, make user notification or something --
		// observe time cost of optimization of table sizes so that
		// this max can be tuned -- the operation is regarded as
		// expensive, so this value should be the max that can
		// be considered reasonable for an automatic action --
		// first guess: 5mb; if records require ~ 30 bytes,
		// (as found with db's overhead on one test system)
		// simplistic figuring gives ~ 175k records in 5mb;
		// 175k IP4 addresses listed at blog comment spam RBLs.
		// will this plugin's data ever get there? who knows
		$lengthmax = 1024 * 1024 * 5;

		if ( $len <= $lengthmax && $fpct > $fragmax ) {
			self::dbglog(
				sprintf('OPTIMIZE: length %d, free %d', $len, $free));
			$wpdb->query("OPTIMIZE TABLE {$tbl}");
		} else if ( $analyze ) {
			self::dbglog(
				sprintf('ANALYZE: length %d, free %d', $len, $free));
			$wpdb->query("ANALYZE TABLE {$tbl}");
		}

		$this->db_unlock_table();
	}
	
	// create the data store table
	protected function db_delete_table() {
		global $wpdb;
		$tbl = $this->db_tablename();
		// 'IF EXISTS' should suppress error if never created
		// drop table removes associated files and data,
		// indices and format, too
		return $wpdb->query("DROP TABLE IF EXISTS {$tbl}");
	}
	
	// create the data store table; use dbDelta, see:
	// https://codex.wordpress.org/Creating_Tables_with_Plugins
	protected function db_create_table() {
		$o = get_option(self::data_vs_opt);
		$v = 0;

		// init version const is/was 1
		if ( ! $o ) {
			// opt did not exist, needs adding
			add_option(self::data_vs_opt, ''.self::data_vs);
		} else {
			$v = 0 + $o;
		}

		// if existing version is not less, leave it be
		if ( $v >= self::data_vs ) {
			return true;
		}

		// opt already existed, needs update
		if ( $o ) {
			update_option(self::data_vs_opt, ''.self::data_vs);
		}
		
		$tbl = $this->db_tablename();

// Nice indenting must be suspended now
// want a table like so:
// address  == dotted IP4 address ; primary key
// hitcount == count of hits
// seeninit == *epoch* time of 1st recorded hit
// seenlast == *epoch* time of last recorded hit
// lasttype == enum('comments', 'pings', 'torx', 'x1', 'x2', 'non', 'white', 'black')
//		set type: torx for whitelist option, non for recording non-hits
//                option, white||black for user entered addresses
//                and a couple for expansion
// varispam == bool set true if lasttype != current type
// 
// charset ascii with case senitive binary collation is suitable
// for the IP address column, and enum 'lasttype' is constrained
// to that, and can reasonably be *assumed* to have the fastest possible
// comparisons
$qs = <<<EOQ
CREATE TABLE $tbl (
  address char(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL default '0.0.0.0',
  hitcount int(11) UNSIGNED NOT NULL default '0',
  seeninit int(11) UNSIGNED NOT NULL default '0',
  seenlast int(11) UNSIGNED NOT NULL default '0',
  lasttype enum('comments', 'pings', 'torx', 'x1', 'x2', 'non', 'white', 'black') CHARACTER SET ascii COLLATE ascii_bin NOT NULL default 'comments',
  varispam tinyint(1) NOT NULL default '0',
  PRIMARY KEY  (address),
  KEY seenlast (seenlast),
  KEY lasttype (lasttype),
  KEY complast (seenlast, lasttype)
);

EOQ;

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($qs);

		return true;
	}
	
	// cache var for the following db_get_address method
	private $db_get_addr_cache = null;

	// get record for an IP address; returns null
	// (as $wpdb->get_row() is documented to do),
	// or associative array
	protected function db_get_address($addr, $lock = true) {
		if ( $this->db_get_addr_cache !== null
			&& $this->db_get_addr_cache[0] === $addr ) {
			return $this->db_get_addr_cache[1];
		}

		global $wpdb;
		$tbl = $this->db_tablename();

		$q = "SELECT * FROM {$tbl} WHERE address = '{$addr}'";
		
		
		if ( $lock )
			$this->db_lock_table();
		$r = $wpdb->get_row($q, ARRAY_A);
		if ( $lock )
			$this->db_unlock_table();

		if ( is_array($r) ) {
			$this->db_get_addr_cache = array($addr, $r);
		} else {
			$this->db_get_addr_cache = null;
		}

		return $r;
	}
	
	// get number of records -- checks the store version options
	// first for whether the table should exist -- returns
	// false if the option does not exist
	protected function db_get_rowcount($lock = true) {
		global $wpdb;
		$tbl = $this->db_tablename();

		if ( $lock )
			$this->db_lock_table();
		$r = $wpdb->get_results(
			"SELECT COUNT(*) FROM {$tbl}", ARRAY_N
		);
		if ( $lock )
			$this->db_unlock_table();

		if ( is_array($r) && isset($r[0]) && isset($r[0][0]) ) {
			return $r[0][0];
		}
		
		return false;
	}

	// general function of select
	protected
	function db_FUNC($f, $where = null, $group = null, $lock = true) {
		global $wpdb;
		$tbl = $this->db_tablename();
		
		$q = sprintf("SELECT %s FROM %s", $f, $tbl);
		if ( $where !== null ) {
			$q .= ' WHERE ' . $where;
		}
		if ( $group !== null ) {
			$q .= ' GROUP BY ' . $group;
		}

		if ( $lock )
			$this->db_lock_table();
		$r = $wpdb->get_results($q, ARRAY_N);
		if ( $lock )
			$this->db_unlock_table();

		if ( is_array($r) ) {
			return $r;
		}
		
		return false;
	}

	// remove where seenlast is < $ts
	protected function db_remove_older_than($ts) {
		global $wpdb;
		$tbl = $this->db_tablename();
		
		$ts = sprintf('%u', 0 + $ts);

		// NOTE: address <> '0.0.0.0' was necessary with mysql
		// commandline client:
		// "safe update [...] without a WHERE that uses a KEY column";
		// and at testing address was the only key. Although this
		// did not prove necessary in WP test installations,
		// it's added for 'noia's sake, and should not affect
		// results as address should never be '0.0.0.0'
		$noid = "address <> '0.0.0.0' AND ";
		$this->db_lock_table();
		$wpdb->get_results(
			"DELETE IGNORE FROM {$tbl} WHERE {$noid}seenlast < {$ts};",
			ARRAY_N
		);
		$r = $wpdb->get_results(
			"SELECT ROW_COUNT();",
			ARRAY_N
		);
		$this->db_unlock_table();

		if ( is_array($r) && isset($r[0]) && isset($r[0][0]) ) {
			return $r[0][0];
		}
		
		return false;
	}

	// remove older rows so that row count == $max
	protected function db_remove_above_max($mx) {
		$ret = false;

		// 'row_count'
		$c = $this->db_get_rowcount();

		do {
			if ( $c === false ) {
				// break rather than return, to get the unlock
				break;
			}
			
			if ( (int)$c <= ((int)$mx+self::db_get_max_pad($mx)) ) {
				// break rather than return, to get the unlock
				$ret = 0;
				break;
			}
			
			global $wpdb;
			$tbl = $this->db_tablename();
			
			// make difference; number to remove
			$c = sprintf('%u', (int)$c - (int)$mx);
	
			// MySQL docs claim LIMIT is MySQL specific;
			// if WP ever supports other DB this will have to
			// be redone
			// NOTE: address <> '0.0.0.0' was necessary with mysql
			// commandline client:
			// "safe update [...] without a WHERE that uses a KEY column";
			// and at testing address was the only key. Although this
			// did not prove necessary in WP test installations,
			// it's added for 'noia's sake, and should not affect
			// results as address should never be '0.0.0.0'
			$noid = "WHERE address <> '0.0.0.0' ";
			$this->db_lock_table();
			$wpdb->get_results(
				"DELETE FROM {$tbl} {$noid}ORDER BY seenlast LIMIT {$c};",
				ARRAY_N
			);
			$r = $wpdb->get_results(
				"SELECT ROW_COUNT();",
				ARRAY_N
			);
			$this->db_unlock_table();
	
			if ( is_array($r) && isset($r[0]) && isset($r[0][0]) ) {
				$ret = (int)$r[0][0];
			}
		} while ( false );

		return $ret;
	}

	// return a pad value for the maximum data store row count option
	// to avoid the condition at max that each new insert triggers
	// another deletion, which is wasteful; the way of figuring the
	// value will always be subject to tuning, and might eventually
	// be made an option
	// pass the actual max option in $mx
	public static function db_get_max_pad($mx) {
		$mx = (int)$mx;
		if ( $mx < 50 ) {
			return 5;
		}
		if ( $mx < 1000 ) {
			return $mx / 10;
		}
		return 100;
	}

	// delete record from address -- uses method
	// added in WP 3.4.0
	protected function db_remove_address($addr, $lock = true) {
		if ( $this->db_get_addr_cache !== null
			&& $this->db_get_addr_cache[0] === $addr ) {
			$this->db_get_addr_cache = null;
		}

		global $wpdb;
		$tbl = $this->db_tablename();
		$r = false;

		$q = "DELETE * FROM {$tbl} WHERE address = '{$addr}'";
		if ( $lock )
			$this->db_lock_table();
		$r = $wpdb->get_results($q, ARRAY_N);
		if ( $lock )
			$this->db_unlock_table();

		return $r;
	}

	// insert record from an associative array
	// $check1st may be false if caller is certain
	// the existence of the record need not be checked
	// NOTE: does *not* lock!
	protected
	function db_insert_array($a, $check1st = true, $lock = true) {
		// optional check for record first
		if ( $check1st !== false ) {
			$r = $this->db_get_address($a['address'], $lock);
			if ( is_array($r) ) {
				return false;
			}
		}

		global $wpdb;
		$tbl = $this->db_tablename();

		if ( $lock )
			$this->db_lock_table();
		$r = $wpdb->insert($tbl, $a,
			array('%s','%d','%d','%d','%s','%d')
		);
		if ( $lock )
			$this->db_unlock_table();

		return $r;
	}
	
	// update record from an associative array
	// will insert record that doesn't exist if $insert is true
	protected
	function db_update_array($a, $insert = true, $lock = true) {
		if ( $lock )
			$this->db_lock_table();

		// insert if record dies not exist
		$r = $this->db_get_address($a['address'], false);
		if ( ! is_array($r) ) {
			$r = false;
			if ( $insert === true ) {
				$r = $this->db_insert_array($a, false, false);
			}
			$this->db_unlock_table();
			return $r;
		}

		global $wpdb;
		$tbl = $this->db_tablename();

		// cache holds record that is changed, so clear it
		$this->db_get_addr_cache = null;

		// update get values in $r with those passed in $a
		// leave address and seeninit alone
		// compare lasttype, set varispam 1 if lasttype differs
		if ( $r['lasttype'] !== $a['lasttype'] ) {
			if ( $r['lasttype'] == 'comments'
				&& $a['lasttype'] == 'pings') {
				$r['varispam'] = 1;
			} else if ( $a['lasttype'] == 'comments'
				&& $r['lasttype'] == 'pings') {
				$r['varispam'] = 1;
			}
		}
		// set lasttype, seenlast
		$r['lasttype'] = $a['lasttype'];
		$r['seenlast'] = $a['seenlast'];
		// add hitcount
		$r['hitcount'] = (int)$r['hitcount'] + (int)$a['hitcount'];
		
		$wh = array('address' => $a['address']);
		$r = $wpdb->update($tbl, $r, $wh,
			array('%s','%d','%d','%d','%s','%d'),
			array('%s')
		);

		if ( $lock )
			$this->db_unlock_table();

		return $r;
	}
	
	// make insert/update array from separate args
	protected
	function db_make_array($addr, $hitincr, $time, $type = 'comments')
	{
		// setup the enum field "lasttype"; avoid assumption
		// that arg and enum keys will match, although
		// they should -- this can be made helpful or fuzzy, later
		$t = 'x1';
		switch ( $type ) {
			case 'comments': $t = 'comments'; break;
			case 'pings'   : $t = 'pings';    break;
			case 'torx'    : $t = 'torx';     break;
			case 'non'     : $t = 'non';      break;
			case 'white'   : $t = 'white';    break;
			case 'black'   : $t = 'black';    break;
			case 'x2'      : $t = 'x2';       break;
			case 'x1'      : $t = 'x1';       break;
			default        : $t = 'x1';       break;
		}

		return array(
			'address'  => $addr,
			'hitcount' => $hitincr,
			'seeninit' => $time,
			'seenlast' => $time,
			'lasttype' => $t,
			'varispam' => 0
		);
	}

	// public: get some info on the data store; e.g., for
	// the widget -- return map where ['k'] is an array
	// of avalable keys, not including 'k'
	public function get_db_info($lock = true) {
		if ( $lock )
			$this->db_lock_table();

		$r = array(
			'k' => array()
		);
		
		//global $wpdb;
		//$wpdb->show_errors();
		// 'row_count'
		$c = $this->db_get_rowcount(false);
		if ( $c === false ) {
			if ( $lock )
				$this->db_unlock_table();
			return false;
		}
		
		$r['k'][] = 'row_count';
		$r['row_count'] = $c;
		
		// common values in locals
		$hour = 3600;
		$day = $hour * 24;
		$week = $day * 7;
		$tf = self::best_time();
		$tm = (int)$tf;
		$types = "lasttype = 'pings' OR lasttype = 'comments'";
		// Update in 1.0.2: should have included blacklist type
		$types .= " OR lasttype = 'black'";

		$w = '' . ($tm - $hour);
		$a = $this->db_FUNC('COUNT(*)',
			"seenlast > {$w} AND ({$types})", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'hour';
			$r['hour'] = $a[0][0];
		}
		$a = $this->db_FUNC('COUNT(*)',
			"seeninit > {$w} AND ({$types})", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'hourinit';
			$r['hourinit'] = $a[0][0];
		}

		$w = '' . ($tm - $day);
		$a = $this->db_FUNC('COUNT(*)',
			"seenlast > {$w} AND ({$types})", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'day';
			$r['day'] = $a[0][0];
		}
		$a = $this->db_FUNC('COUNT(*)',
			"seeninit > {$w} AND ({$types})", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'dayinit';
			$r['dayinit'] = $a[0][0];
		}

		$w = '' . ($tm - $week);
		$a = $this->db_FUNC('COUNT(*)',
			"seenlast > {$w} AND ({$types})", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'week';
			$r['week'] = $a[0][0];
		}
		$a = $this->db_FUNC('COUNT(*)',
			"seeninit > {$w} AND ({$types})", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'weekinit';
			$r['weekinit'] = $a[0][0];
		}

		$a = $this->db_FUNC("SUM(hitcount)", "{$types}", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'htotal';
			$r['htotal'] = $a[0][0];
		}

		$w = 'white';
		$a = $this->db_FUNC('COUNT(*)',
			"lasttype = '{$w}'", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'white';
			$r['white'] = $a[0][0];
		}
		
		$w = 'black';
		$a = $this->db_FUNC('COUNT(*)',
			"lasttype = '{$w}'", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'black';
			$r['black'] = $a[0][0];
		}

		$w = 'torx';
		$a = $this->db_FUNC('COUNT(*)',
			"lasttype = '{$w}'", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'tor';
			$r['tor'] = $a[0][0];
		}
		
		$w = 'non';
		$a = $this->db_FUNC('COUNT(*)',
			"lasttype = '{$w}'", null, false);
		if ( $a !== false && is_array($a[0]) && (int)$a[0][0] > 0 ) {
			$r['k'][] = 'non';
			$r['non'] = $a[0][0];
		}
		
		if ( $lock )
			$this->db_unlock_table();

		$tf = self::best_time() - $tf;
		self::dbglog('database info gathered in ' . $tf . ' seconds');
		return $r;
	}
} // End class Spam_BLIP_class

// global instance of plugin class
global $Spam_BLIP_plugin1_evh_instance_1;
if ( ! isset($Spam_BLIP_plugin1_evh_instance_1) ) :
	$Spam_BLIP_plugin1_evh_instance_1 = null;
endif; // global instance of plugin class

else :
	wp_die('class name conflict: Spam_BLIP_class in ' . __FILE__);
endif; // if ( ! class_exists('Spam_BLIP_class') ) :


/**
 * class for Spam BLIP info widget
 */
if ( ! class_exists('Spam_BLIP_widget_class') ) :
class Spam_BLIP_widget_class extends WP_Widget {
	// an instance of the main plugun class
	protected $plinst;
	
	public function __construct() {
		$this->plinst = Spam_BLIP_class::get_instance(false);
	
		$cl = __CLASS__;
		// Label shown on widgets page
		$lb =  __('Spam BLIP', 'spambl_l10n');
		// Description shown under label shown on widgets page
		$desc = __('Display comment and ping spam hit information, and database table row count', 'spambl_l10n');
		$opts = array('classname' => $cl, 'description' => $desc);

		// control opts width affects the parameters form,
		// height is ignored.  Width 400 allows long text fields
		// (not as log as most URL's), and informative (long) labels
		//$copts = array('width' => 400, 'height' => 500);
		$copts = array();

		parent::__construct($cl, $lb, $opts, $copts);
	}

	public function widget($args, $instance) {
		$opt = $this->plinst->get_widget_option();
		if ( $opt != 'true' ) {
			return;
		}
		
		//extract($args);
		// when this was 1st written WP core used extract() freely, but
		// it is now a function non grata: one named concern is
		// readability; obscure origin of vars seen in code, so readers:
		// the array elements in the explicit extraction below will
		// appear as variable names later.
		foreach(array(
			'before_widget',
			'after_widget',
			'before_title',
			'after_title') as $k) {
			$$k = isset($args[$k]) ? $args[$k] : '';
		}
	

		$ud  = $this->plinst->get_usedata_option();
		$bc  = $this->plinst->get_comments_open_option();
		$bp  = $this->plinst->get_pings_open_option();
		$inf = false;
		if ( $ud != 'false' && ($bc != 'false' || $bp != 'false') ) {
			$inf = $this->plinst->get_db_info();
		}
		
		// note *no default* for title; allow empty title so that
		// user may place this below another widget with
		// apparent continuity (subject to filters)
		$title = apply_filters('widget_title',
			empty($instance['title']) ? '' : $instance['title'],
			$instance, $this->id_base);

		$cap = array_key_exists('caption', $instance)
			? $instance['caption'] : false;

		$showopt = array_key_exists('OPT', $instance)
			? $instance['OPT'] : false;

		$url = array_key_exists('URL', $instance)
			? $instance['URL'] : false;

		if ( $showopt == 'true' ) {
			// get some options to show if true
			$br  = $this->plinst->get_user_regi_option();
			$tw  = $this->plinst->get_torwhite_option();
			$bo  = $this->plinst->get_bailout_option();
			$ce  = $this->plinst->get_chkexist_option();
			$rn  = $this->plinst->get_rec_non_option();
			$ps  = $this->plinst->get_rej_not_option();
			$showopt = false;
			if ( $bc != 'false' || $bp != 'false' || $br != 'false' ||
				$tw != 'false' || $ps != 'false' ||
				$bo != 'false' || $ce != 'false' || $rn != 'false' ) {
				$showopt = true;
			}
		}
	
		echo $before_widget;

		if ( $title ) {
			printf("%s%s%s\n", $before_title, $title, $after_title);
		}

		// use no class, but do use deprecated align
		$code = sprintf('Spam_BLIP_widget_%06u', rand());
		// overdue: 1.0.4 removed deprecated align
		$dv = '<div id="'.$code.'" class="widget">';
		echo "\n<!-- Spam BLIP plugin: info widget div -->\n{$dv}";

		$wt = 'wptexturize';  // display with char translations
		$htype = 'h6';        // depends on css of theme; who knows?

		// show set options
		if ( $showopt === true ) {
			printf("\n\t<{$htype}>%s</{$htype}>",
				$wt(__('Options:', 'spambl_l10n'))
			);
			echo "\n\t<ul>";

			if ( $bc != 'false' ) {
				printf("\n\t\t<li>%s</li>",
					$wt(__('Checking for comment spam', 'spambl_l10n'))
				);
			}
			if ( $bp != 'false' ) {
				printf("\n\t\t<li>%s</li>",
					$wt(__('Checking for ping spam', 'spambl_l10n'))
				);
			}
			if ( $br != 'false' ) {
				printf("\n\t\t<li>%s</li>",
					$wt(__('Checking user registration', 'spambl_l10n'))
				);
			}
			if ( $ce != 'false' ) {
				printf("\n\t\t<li>%s</li>",
					$wt(__('Checking in saved spam', 'spambl_l10n'))
				);
			}
			if ( $bo != 'false' ) {
				printf("\n\t\t<li>%s</li>",
					$wt(__('Bailing out on hits', 'spambl_l10n'))
				);
			}
			if ( $rn != 'false' ) {
				printf("\n\t\t<li>%s</li>",
					$wt(__('Saving non-hits', 'spambl_l10n'))
				);
			}
			if ( $tw != 'false' ) {
				printf("\n\t\t<li>%s</li>",
					$wt(__('Whitelisting TOR exits', 'spambl_l10n'))
				);
			}
			if ( $ps != 'false' ) {
				printf("\n\t\t<li>%s</li>",
					$wt(__('Not rejecting hits', 'spambl_l10n'))
				);
			}
			echo "\n\t</ul>\n";
		}
		
		if ( $inf ) {
			printf("\n\t<{$htype}>%s</{$htype}>",
				$wt(__('Records:', 'spambl_l10n'))
			);
			echo "\n\t<ul>";

			// keys for desired info, in order
			$kord = array('row_count', 'white', 'black', 'non', 'tor',
						'hour', 'hourinit', 'day', 'dayinit', 'week',
						'weekinit', 'htotal');
			foreach ( $kord as $k ) {
				if ( ! in_array($k, $inf['k'])  ) {
					continue;
				}
				$v = $inf[$k];
				switch ( $k ) {
					case 'row_count':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d address listed',
							   '%d addresses listed',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'white':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d user whitelist address',
							   '%d user whitelist addresses',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'black':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d user blacklist address',
							   '%d user blacklist addresses',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'non':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d non-hit address',
							   '%d non-hit addresses',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'tor':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d tor exit node',
							   '%d tor exit nodes',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'hour':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d address in the past hour',
							   '%d addresses in the past hour',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'hourinit':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d new address in the past hour',
							   '%d new addresses in the past hour',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'day':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d address in the past day',
							   '%d addresses in the past day',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'dayinit':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d new address in the past day',
							   '%d new addresses in the past day',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'week':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d address in the past week',
							   '%d addresses in the past week',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'weekinit':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d new address in the past week',
							   '%d new addresses in the past week',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					case 'htotal':
						printf("\n\t\t<li>%s</li>",
							sprintf($wt(_n('%d hit in all records',
							   'total of %d hits in all records',
							   $v, 'spambl_l10n')), $v)
						);
						break;
					default:
						break;
				}
			}
			echo "\n\t</ul>\n";
		}

		if ( $url == 'true' ) {
			printf(__('<br><p>
				DNS blacklist spam checking by the
				<a href="%s" target="_blank"><em>Spam BLIP</em></a>
				plugin.
				</p>', 'spambl_l10n'),
				Spam_BLIP_class::plugin_webpage
			);
		}
		if ( $cap ) {
			// overdue: 1.0.4 removed deprecated align
			echo '<p><span>' . $wt($cap) . '</span></p>';
		}
		echo "\n</div>\n";
		echo "<!-- Spam BLIP plugin: info widget div ends -->\n";

		echo $after_widget;
	}

	public function update($new_instance, $old_instance) {
		// form ctls: OPT and URL are checkboxes
		$i = array(
			'title' => '',
			'caption' => '',
			'OPT' => 'false',
			'URL' => 'false'
		);
		
		if ( is_array($old_instance) ) {
			array_merge($i, $old_instance);
		}
		
		if ( is_array($new_instance) ) {
			// for pesky checkboxes; not present if unchecked, but
			// present 'false' is wanted
			foreach ( $i as $k => $v ) {
				if ( array_key_exists($k, $new_instance) ) {
					$t = $new_instance[$k];
					$i[$k] = $t;
				}
			}
		}

		if ( ! array_key_exists('caption', $i) ) {
			$i['caption'] = '';
		}
		if ( ! array_key_exists('title', $i) ) {
			$i['title'] = '';
		}
		if ( ! array_key_exists('OPT', $i) || $i['OPT'] == '' ) {
			$i['OPT'] = 'false';
		}
		if ( ! array_key_exists('URL', $i) || $i['URL'] == '' ) {
			$i['URL'] = 'false';
		}

		return $i;
	}

	public function form($instance) {
		$wt = 'wptexturize';  // display with char translations
		$ht = 'Spam_BLIP_php52_htmlent'; // escape w/o char translations
		$et = 'rawurlencode'; // %XX -- for transfer

		// form ctls: URL is checkbox
		$val = array(
			'title' => '',
			'caption' => '',
			'OPT' => 'false',
			'URL' => 'false'
		);
		$instance = wp_parse_args((array)$instance, $val);

		$val = '';
		if ( array_key_exists('title', $instance) ) {
			$val = $ht($instance['title']);
		}
		$id = $this->get_field_id('title');
		$nm = $this->get_field_name('title');
		$tl = $wt(__('Widget title:', 'spambl_l10n'));

		?>

		<p><label for="<?php echo $id; ?>"><?php echo $tl; ?></label>
		<input class="widefat" id="<?php echo $id; ?>"
			style="overflow: auto;"
			name="<?php echo $nm; ?>"
			type="text"
			value="<?php echo $val; ?>" /></p>

		<?php
		$val = $ht($instance['caption']);
		$id = $this->get_field_id('caption');
		$nm = $this->get_field_name('caption');
		$tl = $wt(__('Caption:', 'spambl_l10n'));
		?>
		<p><label for="<?php echo $id; ?>"><?php echo $tl; ?></label>
		<input class="widefat" id="<?php echo $id; ?>"
			style="overflow: auto;"
			name="<?php echo $nm; ?>"
			type="text"
			value="<?php echo $val; ?>" /></p>

		<?php
		// show options checkbox
		$val = $instance['OPT'];
		$id = $this->get_field_id('OPT');
		$nm = $this->get_field_name('OPT');
		$ck = $val == 'true' ? ' checked="checked"' : ''; $val = 'true';
		$tl = $wt(__('Show <em>Spam BLIP</em> options:&nbsp;', 'swfput_l10n'));
		?>
		<p><label for="<?php echo $id; ?>"><?php echo $tl; ?></label>
		<input class="widefat" id="<?php echo $id; ?>"
			name="<?php echo $nm; ?>" style="width:16%;" type="checkbox"
			value="<?php echo $val; ?>"<?php echo $ck; ?> /></p>

		<?php
		// show link checkbox
		$val = $instance['URL'];
		$id = $this->get_field_id('URL');
		$nm = $this->get_field_name('URL');
		$ck = $val == 'true' ? ' checked="checked"' : ''; $val = 'true';
		$tl = $wt(__('Show <em>Spam BLIP</em> link:&nbsp;', 'swfput_l10n'));
		?>
		<p><label for="<?php echo $id; ?>"><?php echo $tl; ?></label>
		<input class="widefat" id="<?php echo $id; ?>"
			name="<?php echo $nm; ?>" style="width:16%;" type="checkbox"
			value="<?php echo $val; ?>"<?php echo $ck; ?> /></p>

		<?php
	}
} // End class Spam_BLIP_widget_class
else :
	wp_die('class name conflict: Spam_BLIP_widget_class in ' . __FILE__);
endif; // if ( ! class_exists('Spam_BLIP_widget_class') ) :


/**********************************************************************\
 *  plugin 'main()' level code                                        *
\**********************************************************************/

// Instance not needed (or wanted) if uninstalling; the registered
// uninstall hook is saved by WP in an option so it is presistent,
// and the plugin's static uninstall method will be called.
// Else, make an instance, which triggers running.
if ( ! defined('WP_UNINSTALL_PLUGIN')
	&& $Spam_BLIP_plugin1_evh_instance_1 === null ) {
	$Spam_BLIP_plugin1_evh_instance_1 = Spam_BLIP_class::instantiate();
}

// End PHP script:
?>
