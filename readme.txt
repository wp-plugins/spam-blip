=== Spam_BLIP ===
Contributors: EdHynan
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick%DONATE_LINK%hosted_button_id=4Q2Y8ZUG8HXLC
Tags: anti-spam, blog, comment, comments, content, links, network, plugin, post, Post, posts, security, spam, wordpress
Requires at least: 3.0.2
Tested up to: 3.7
Stable tag: 1.0.0
Text Domain: spambl_l10n
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Spam BLIP stops comment spam before it is posted, using DNS blacklists, existing comments marked as spam, and user defined lists.

== Description ==

If you accidentally named your new twins Viagra and Nike, then
you've been seeing those names much too much. You need Spam BLIP!

Spam BLIP stops comment and ping spam from being posted, primarily by
checking the IP address attempting to post a comment in one or more
of the public DNS blacklists. A number of options are available
to refine the check, and with the option defaults, a DNS lookup
is only performed the first time an address *attempts to post* a
comment; thereafter, the address might quickly 'pass' because it
was not listed, or quickly be rejected because it was listed.
Spam BLIP creates, and maintains, a database table for this purpose,
and database lookups are quite fast. Therefore, concerns about
DNS lookup time can be limited to an initial comment attempt.

Here are some features of Spam BLIP to consider if you are
not yet falling over yourself to get it installed:

*	When WordPress is producing a page for a visitor, it checks
	whether comments are open for each post, and it allows plugins
	to "filter" the check. Spam BLIP uses that filter, but *does not*
	do DNS lookups at this stage, because DNS lookups can take
	perceptible time. Spam BLIP *does* check optional user-set
	black and white lists, and optionally existing comments that
	are marked as spam, and of course Spam BLIP's own database records.
	Those checks are fast, so they should not have a perceptible
	effect on page loading. Furthermore, on pages with multiple
	posts, WordPress runs the filter for each, but Spam BLIP
	stores the first result, so even the fast checks are not
	repeated.

*	When a comment is actually submitted, Spam BLIP does the above
	checks, then the DNS lookup only if necessary. At this stage,
	if the DNS lookup causes a perceptible delay, a real human
	(or *very* clever pet) making the comment should perceive it
	as mere server-side processing. As for spammer robots . . .
	let them wait.

*	Spam BLIP comes configured with blacklist domains that have
	worked well during development, so a user should not need to
	be concerned with the blacklists. But, there is an advanced
	option to add or delete, activate or disable (yet save)
	list domains; and configure the interpretation of a return
	from a successful lookup.

*	Spam BLIP provides user-set whitelist and blacklist options.

*	Spam BLIP provides options to check for pings/trackbacks, and
	for user registrations. (The option to blacklist-check user
	registration is off by default. See "Tips" under the help
	tab on the Spam BLIP settings page.)

*	Spam BLIP provides options to configure a 'Time To Live' (TTL)
	for its database records, and a maximum number of records.
	The TTL is important because, generally, an IP address should
	not be marked permanently. Consider an ISP that quickly
	disables any account that is found to be spamming. An honest
	ISP is also a victim of spammer abuse, and will need to reuse
	addresses. DNS blacklist operators provide means for IP
	address owners to get records removed -- Spam BLIP provides
	a configurable TTL for its records. (Database table maintenance
	is triggered approximately hourly by a WordPress cron event.)

*	Spam BLIP will optionally check if a commenter address is a
	TOR exit node. TOR (The Onion Router) is an important protection
	for people who need or wish for anonymity. You may want to
	accept comments from TOR users (you should), but unfortunately
	spammers have exploited and abused TOR, which has led some
	DNS blacklist operators to include TOR exit node addresses
	whether or not it is known that the address is spamming. If you
	enable this option (you should), it might let some spam get
	through. In this case, mark the comment as spam, and use the
	Spam BLIP option to check existing comments marked as spam
	(but this has the disadvantage of not being subject to the
	record TTL, and the address will continually rejected until
	the comment deleted, or the option is disabled); or use
	Spam BLIP in concert with another sort of spam filter, such
	as one that analyzes comment content. (Please report any
	conflict with other, non-DNS blacklist type spam plugins.
	Note that Spam BLIP is not expected to work in concert with
	other DNS-type spam plugins.)

*	Spam BLIP includes a widget that will show options and records
	information. The widget might or might not be an enhancement
	to your page, but in any case it should provide feedback
	while you evaluate Spam BLIP, so it might be used temporarily.

== Installation ==

Spam BLIP is installed through the WordPress administrative interface,
and does not have additional requirements for installation.

== Frequently Asked Questions ==

= Is this really a FAQ? =

No.

= Is this plugin really about canned meat? =

No. The canned meat is laudable, and venerated. You are not to be
blamed for this confusion; the blame is on something completely
different.

= Hey, how about an *authentic, genuine* imitation FAQ? =

Okay. What is the 'BLIP' in "Spam BLIP"? Think 'BLacklist IP'.

= Any more? Howzabout the boring version number? =

Right you are. Version numbers are useful, but sometimes fail
to enliven the imagination. So, as it happens, this plugin was
submitted to WordPress.org one day after the release they
call Basie, and in that spirit this release of this plugin
will be named Benny, which you may take to refer to Benny Goodman,
or "Who Put the Benzedrine in Mrs. Murphy's Ovaltine" or 'Benefits?
You want benefits? Are you an enemy of the Plutocratic Revolution?'
Choose any one, they all work.

== Screenshots ==

1. The Spam BLIP optional information widget display.

2. The Spam BLIP settings page TTL and maximum records options.

3. The Spam BLIP DNS blackist domain editor (option).

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
* Initial release.
	
