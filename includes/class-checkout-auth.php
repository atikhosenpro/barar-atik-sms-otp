<?php
/**
 * Checkout authentication: the WooCommerce "enable login during checkout"
 * area redrawn as switchable tabs, plus an optional popup and two free
 * content slots.
 *
 * WooCommerce renders one rigid block there (templates/checkout/form-login.php
 * -> global/form-login.php). It is replaced by three tabs instead:
 *
 *   Phone OTP Login      - this plugin, context wc_login
 *   Phone OTP Registration - this plugin, context wc_register
 *   Username / Email Login- WooCommerce's own form, kept verbatim
 *
 * Each tab is independently switchable and the phone number tab is the
 * default one. The native markup is captured with output buffering and
 * re-printed inside our third tab, so WooCommerce keeps owning the username
 * and password POST (wc_ajax login, its own nonce, its own redirects) and
 * themes that overrode the templates keep working.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Checkout_Auth {

	/**
	 * Tab keys, in display order.
	 */
	const TAB_LOGIN   = 'phone_login';
	const TAB_REGISTER= 'phone_register';
	const TAB_NATIVE  = 'account';

	/**
	 * WooCommerce's own checkout login markup, captured for re-printing.
	 *
	 * @var string
	 */
	private $native = '';

	/**
	 * Whether the capture already ran.
	 *
	 * @var bool
	 */
	private $captured = false;

	/**
	 * Popup instance counter, so every popup gets unique element ids.
	 *
	 * @var int
	 */
	private $popup_seq = 0;

	/**
	 * Hook everything.
	 */
	public function __construct() {
		/*
		 * Priority 9: take WooCommerce's login block out of the page and keep
		 * its markup, so it can be printed inside the "account" tab. Runs
		 * before WooCommerce's own callback at priority 10.
		 */
		add_action( 'woocommerce_before_checkout_form', array( $this, 'capture_native' ), 9 );

		// Priority 10: where WooCommerce would have printed its login block.
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_tabs' ), 10 );

		// Optional popup: a trigger plus the dialog itself.
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_popup' ), 10 );

		// Administrator content slots.
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_top_content' ), 5 );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'render_bottom_content' ), 10 );
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * Is this the logged-out checkout screen?
	 *
	 * @return bool
	 */
	private function on_checkout() {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_user_logged_in();
	}

	/**
	 * Should the tabbed authentication area be drawn at all?
	 *
	 * @return bool
	 */
	private function enabled() {
		if ( '1' !== Barar_Atik_Settings::get( 'checkout_auth_tabs', '1' ) ) {
			return false;
		}
		return ( $this->tab_login() || $this->tab_register() || $this->tab_native() );
	}

	/**
	 * Phone OTP login tab available?
	 *
	 * @return bool
	 */
	private function tab_login() {
		return '1' === Barar_Atik_Settings::get( 'checkout_otp_login', '1' )
			&& Barar_Atik_Settings::context_enabled( 'wc_login' );
	}

	/**
	 * Phone OTP registration tab available?
	 *
	 * @return bool
	 */
	private function tab_register() {
		return '1' === Barar_Atik_Settings::get( 'checkout_otp_register', '0' )
			&& Barar_Atik_Settings::context_enabled( 'wc_register' )
			&& $this->registration_allowed();
	}

	/**
	 * The native username/email tab available?
	 *
	 * The "login without an email address" switch removes it: with it on the
	 * phone number is the only way in, which is what that switch promised.
	 *
	 * Deliberately not gated on WooCommerce's own "enable login at checkout"
	 * options - this plugin's switch is what governs the tab, and the form
	 * itself works regardless of those options (see build_native()).
	 *
	 * @return bool
	 */
	private function tab_native() {
		if ( '1' === Barar_Atik_Settings::get( 'checkout_login_no_email', '0' ) ) {
			return false;
		}
		return function_exists( 'woocommerce_login_form' );
	}

	/**
	 * Is signup allowed from checkout?
	 *
	 * @return bool
	 */
	private function registration_allowed() {
		return 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' )
			|| 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
	}

	/* ---------------------------------------------------------------------
	 * Native form capture
	 * ------------------------------------------------------------------ */

	/**
	 * Remove WooCommerce's login block from the page and remember its markup.
	 *
	 * @return void
	 */
	public function capture_native() {
		if ( $this->captured || ! $this->on_checkout() || ! $this->enabled() ) {
			return;
		}
		$this->captured = true;

		if ( ! function_exists( 'woocommerce_checkout_login_form' ) ) {
			return;
		}

		// Remove it wherever it was hooked; the original is at priority 10.
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form' );

		/*
		 * WooCommerce's login template fires `woocommerce_login_form` from
		 * inside the <form>. With inline injection enabled that action would
		 * append an OTP panel to the captured copy, and the panel hides the
		 * username and password rows it finds - which, printed inside the
		 * "Username / Email Login" tab, is that tab's own form. So the capture
		 * runs with inline injection suspended.
		 */
		$this->native = $this->build_native();
	}

	/**
	 * Build the markup for the Username / Email tab.
	 *
	 * WooCommerce's checkout block only prints its login form when one of the
	 * "enable login at checkout" options is on. The tab follows this plugin's
	 * own switch instead, so an empty capture falls back to rendering
	 * WooCommerce's login form directly - the same form, the same nonce, the
	 * same wp_loaded -> process_login() handler (which never consults those
	 * options). Either way the shopper can log in with a username or an email
	 * address from the checkout page.
	 *
	 * @return string
	 */
	private function build_native() {
		$plugin = Barar_Atik_Plugin::instance();

		ob_start();
		if ( $plugin && $plugin->frontend ) {
			$plugin->frontend->without_inline(
				function () {
					if ( function_exists( 'woocommerce_checkout_login_form' ) ) {
						woocommerce_checkout_login_form();
					}
				}
			);
		} elseif ( function_exists( 'woocommerce_checkout_login_form' ) ) {
			woocommerce_checkout_login_form();
		}
		$html = (string) ob_get_clean();

		if ( false !== strpos( $html, 'woocommerce-form-login' ) || ! function_exists( 'woocommerce_login_form' ) ) {
			return $html;
		}

		// WooCommerce printed nothing (both of its options are off): render
		// the form itself, already revealed - there is no toggle to hide.
		$redirect = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
		$args     = array(
			'redirect' => $redirect,
			'hidden'   => false,
		);

		ob_start();
		if ( $plugin && $plugin->frontend ) {
			$plugin->frontend->without_inline(
				function () use ( $args ) {
					woocommerce_login_form( $args );
				}
			);
		} else {
			woocommerce_login_form( $args );
		}
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Markup
	 * ------------------------------------------------------------------ */

	/**
	 * The tab list, in order, with labels.
	 *
	 * @return array<string,string>
	 */
	private function tabs() {
		$tabs = array();

		if ( $this->tab_login() ) {
			$tabs[ self::TAB_LOGIN ] = Barar_Atik_Settings::label( 'tab_auth_login' );
		}
		if ( $this->tab_register() ) {
			$tabs[ self::TAB_REGISTER ] = Barar_Atik_Settings::label( 'tab_auth_register' );
		}
		if ( $this->tab_native() ) {
			if ( '' === trim( $this->native ) ) {
				// The capture did not run (a theme that drops the hook, or an
				// unusual template), so build it now - still with inline
				// injection suspended, for the reason documented there.
				$this->native = $this->build_native();
			}
			if ( '' !== trim( $this->native ) ) {
				$tabs[ self::TAB_NATIVE ] = Barar_Atik_Settings::label( 'tab_auth_account' );
			}
		}

		return $tabs;
	}

	/**
	 * The tab that must be active on load: Phone OTP Login.
	 *
	 * @param array<string,string> $tabs Tabs.
	 * @return string
	 */
	private function default_tab( $tabs ) {
		if ( isset( $tabs[ self::TAB_LOGIN ] ) ) {
			return self::TAB_LOGIN;
		}
		if ( isset( $tabs[ self::TAB_REGISTER ] ) ) {
			return self::TAB_REGISTER;
		}
		$keys = array_keys( $tabs );
		return $keys ? (string) $keys[0] : '';
	}

	/**
	 * Print the tab bar and the tab bodies.
	 *
	 * @return void
	 */
	public function render_tabs() {
		if ( ! $this->on_checkout() || ! $this->enabled() ) {
			return;
		}

		$tabs = $this->tabs();
		if ( ! $tabs ) {
			return;
		}

		$this->panel_markup( $tabs, $this->default_tab( $tabs ), '' );
	}

	/**
	 * Build one tab group. Used for the inline group and for every popup, so
	 * both share exactly the same markup and behaviour.
	 *
	 * @param array<string,string> $tabs   Tab key => label.
	 * @param string               $active Tab key shown first.
	 * @param string               $suffix Unique id suffix ('' for the page copy).
	 * @return void
	 */
	private function panel_markup( $tabs, $active, $suffix ) {
		$plugin = Barar_Atik_Plugin::instance();
		if ( ! $plugin || ! $plugin->frontend ) {
			return;
		}

		$id = 'barar-checkout-auth' . ( '' !== $suffix ? '-' . $suffix : '' );
		$redirect = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
		?>
		<div class="barar-checkout-auth" id="<?php echo esc_attr( $id ); ?>" data-barar-checkout-auth>
			<div class="barar-checkout-auth__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Checkout sign in options', 'barar-atik-sms-otp' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button
						type="button"
						class="barar-checkout-auth__tab<?php echo $key === $active ? ' is-active' : ''; ?>"
						role="tab"
						id="<?php echo esc_attr( $id . '-tab-' . $key ); ?>"
						aria-controls="<?php echo esc_attr( $id . '-panel-' . $key ); ?>"
						aria-selected="<?php echo $key === $active ? 'true' : 'false'; ?>"
						data-barar-tab="<?php echo esc_attr( $key ); ?>"<?php echo '' === $label ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $tabs as $key => $label ) : ?>
				<div
					class="barar-checkout-auth__panel"
					id="<?php echo esc_attr( $id . '-panel-' . $key ); ?>"
					role="tabpanel"
					data-barar-panel="<?php echo esc_attr( $key ); ?>"
					<?php echo $key === $active ? '' : 'hidden'; ?>>
					<?php $this->panel_body( $key, $suffix, $redirect ); ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The body of one tab.
	 *
	 * @param string $key      Tab key.
	 * @param string $suffix   Unique id suffix.
	 * @param string $redirect Redirect after a successful sign in.
	 * @return void
	 */
	private function panel_body( $key, $suffix, $redirect ) {
		$frontend = Barar_Atik_Plugin::instance()->frontend;

		if ( self::TAB_LOGIN === $key ) {
			$this->otp_body( $frontend, 'wc_login', $suffix, $redirect, Barar_Atik_Settings::label( 'txt_checkout_login' ) );
			return;
		}

		if ( self::TAB_REGISTER === $key ) {
			$this->otp_body( $frontend, 'wc_register', $suffix, $redirect, Barar_Atik_Settings::label( 'txt_checkout_register' ) );
			return;
		}

		// WooCommerce's own markup, reprinted verbatim. In a popup the element
		// ids are suffixed so the two copies never collide.
		$html = $this->native;
		if ( '' !== $suffix ) {
			$html = $this->suffix_ids( $html, $suffix );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own escaped markup.
		echo $html;
	}

	/**
	 * One phone OTP form inside a card.
	 *
	 * @param object $frontend Frontend component.
	 * @param string $context  wc_login|wc_register.
	 * @param string $suffix   Unique id suffix.
	 * @param string $redirect Redirect after success.
	 * @param string $note     Note printed above the form.
	 * @return void
	 */
	private function otp_body( $frontend, $context, $suffix, $redirect, $note ) {
		echo '<div class="barar-checkout-otp">';
		if ( '' !== $note ) {
			echo '<p class="barar-checkout-otp__note">' . esc_html( $note ) . '</p>';
		}
		$frontend->render_form(
			$context,
			array(
				'mode'       => 'panel',
				'redirect'   => $redirect,
				'instance'   => $suffix,
				'standalone' => true,
			)
		);
		echo '</div>';
	}

	/**
	 * Append a suffix to every id="..." and for="..." in a markup string.
	 *
	 * @param string $html   Markup.
	 * @param string $suffix Suffix.
	 * @return string
	 */
	private function suffix_ids( $html, $suffix ) {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/\b(id|for)="([^"]+)"/',
			static function ( $matches ) use ( $suffix ) {
				return $matches[1] . '="' . esc_attr( $matches[2] . '-' . $suffix ) . '"';
			},
			$html
		);
	}

	/* ---------------------------------------------------------------------
	 * Popup
	 * ------------------------------------------------------------------ */

	/**
	 * Print the popup trigger and the dialog.
	 *
	 * @return void
	 */
	public function render_popup() {
		if ( '1' !== Barar_Atik_Settings::get( 'checkout_popup', '0' ) ) {
			return;
		}
		if ( ! $this->on_checkout() || ! $this->enabled() ) {
			return;
		}

		$tabs = $this->tabs();
		if ( ! $tabs ) {
			return;
		}

		$this->popup_seq++;
		$suffix = 'popup' . $this->popup_seq;
		$label  = Barar_Atik_Settings::label( 'checkout_popup_label' );
		$title  = Barar_Atik_Settings::label( 'checkout_popup_title' );
		?>
		<div class="barar-auth-popup" data-barar-auth-popup="<?php echo esc_attr( $suffix ); ?>">
			<button type="button" class="barar-auth-popup__open" data-barar-popup-open aria-expanded="false" aria-controls="<?php echo esc_attr( 'barar-auth-popup-dialog-' . $suffix ); ?>"<?php echo '' === $label ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>>
				<?php echo esc_html( $label ); ?>
			</button>

			<div class="barar-auth-popup__dialog" id="<?php echo esc_attr( 'barar-auth-popup-dialog-' . $suffix ); ?>" role="dialog" aria-modal="true"<?php echo '' !== $title ? ' aria-label="' . esc_attr( $title ) . '"' : ''; // phpcs:ignore ?> hidden>
				<div class="barar-auth-popup__backdrop" data-barar-popup-close></div>
				<div class="barar-auth-popup__panel" role="document">
					<button type="button" class="barar-auth-popup__close" data-barar-popup-close aria-label="<?php esc_attr_e( 'Close', 'barar-atik-sms-otp' ); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
					<?php if ( '' !== $title ) : ?>
					<h2 class="barar-auth-popup__title"><?php echo esc_html( $title ); ?></h2>
					<?php endif; ?>
					<?php $this->panel_markup( $tabs, $this->default_tab( $tabs ), $suffix ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Administrator content slots
	 * ------------------------------------------------------------------ */

	/**
	 * Content above the checkout form.
	 *
	 * @return void
	 */
	public function render_top_content() {
		$this->slot( 'checkout_top_content' );
	}

	/**
	 * Content below the checkout form.
	 *
	 * @return void
	 */
	public function render_bottom_content() {
		$this->slot( 'checkout_bottom_content' );
	}

	/**
	 * Print one administrator content slot (HTML + shortcodes).
	 *
	 * @param string $key Settings key.
	 * @return void
	 */
	private function slot( $key ) {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$content = (string) Barar_Atik_Settings::get( $key, '' );
		if ( '' === trim( $content ) ) {
			return;
		}

		echo '<div class="barar-checkout-content barar-checkout-content--' . esc_attr( str_replace( '_', '-', $key ) ) . '">';
		echo do_shortcode( wp_kses_post( $content ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- administrator content, kses filtered.
		echo '</div>';
	}

	}