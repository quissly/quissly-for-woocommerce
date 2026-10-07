<?php
/**
 * Quissly Setup - the onboarding screen, in the Shopify app's design. The markup is shared
 * with quissly-for-magento and quissly-for-cs-cart (assets/js/quissly-setup.js reads it by
 * its data-q-* attributes); keep them in step. Included from Quissly_Setup::render().
 *
 * @package Quissly_For_WooCommerce
 *
 * @var string     $step      Current step.
 * @var bool       $connected Whether the store is connected.
 * @var array|null $plans     Plan view (null: not connected, or plans unavailable).
 * @var array      $steps     Step key => label.
 * @var int        $done      Steps completed.
 * @var string     $domain    Storefront host.
 * @var WP_User    $user      Signed-in admin.
 * @var string     $draft     The drafted workspace description.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The Shopify app's provisioning rows, in its order.
$quissly_rows       = array(
	'store'        => __( 'Store identity', 'quissly-for-woocommerce' ),
	'organization' => __( 'Organization', 'quissly-for-woocommerce' ),
	'project'      => __( 'Project', 'quissly-for-woocommerce' ),
	'qsearch'      => __( 'QSearch service', 'quissly-for-woocommerce' ),
	'qchat'        => __( 'QChat service', 'quissly-for-woocommerce' ),
	'catalog'      => __( 'Catalog import', 'quissly-for-woocommerce' ),
);
$quissly_store_name = Quissly_Setup_Input::suggestName( Quissly_Wizard::store_display_name() );
?>
<div class="q-setup" data-q-setup data-config="<?php echo esc_attr( wp_json_encode( $this->config( $step, $plans ) ) ); ?>">
	<div class="q-band">
		<svg class="q-band__pattern" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
			<g fill="none" stroke="#b8d432" stroke-opacity="0.24" stroke-width="0.4">
				<circle cx="86" cy="-14" r="30"/><circle cx="86" cy="-14" r="42"/><circle cx="86" cy="-14" r="54"/>
				<circle cx="8" cy="112" r="26"/><circle cx="8" cy="112" r="38"/>
				<circle cx="118" cy="66" r="34"/><circle cx="118" cy="66" r="46"/>
			</g>
		</svg>
		<div class="q-band__row">
			<img class="q-band__logo" src="<?php echo esc_url( QUISSLY_PLUGIN_URL . 'assets/images/quissly-wordmark.svg' ); ?>" alt="Quissly" height="20">
			<div class="q-band__titles">
				<div class="q-band__title"><?php esc_html_e( 'Let\'s get Quissly running on your store', 'quissly-for-woocommerce' ); ?></div>
				<div class="q-band__sub"><?php esc_html_e( 'Three short steps. Nothing reaches your storefront until you go live.', 'quissly-for-woocommerce' ); ?></div>
			</div>
			<div class="q-band__progress">
				<div class="q-band__progress-label">
					<span><?php esc_html_e( 'Setup progress', 'quissly-for-woocommerce' ); ?></span>
					<?php /* translators: 1: steps done, 2: steps in all. */ ?>
					<span><?php echo esc_html( sprintf( __( '%1$d of %2$d', 'quissly-for-woocommerce' ), $done, count( $steps ) ) ); ?></span>
				</div>
				<div class="q-meter" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int) count( $steps ); ?>" aria-valuenow="<?php echo (int) $done; ?>">
					<span style="width: <?php echo (int) round( $done / count( $steps ) * 100 ); ?>%"></span>
				</div>
			</div>
		</div>
		<ol class="q-stepper">
			<?php $quissly_index = 0; ?>
			<?php foreach ( $steps as $quissly_key => $quissly_label ) : ?>
				<?php if ( $quissly_index > 0 ) : ?>
					<li class="q-stepper__line" aria-hidden="true"></li>
				<?php endif; ?>
				<li class="q-stepper__item">
					<button type="button" data-q-goto="<?php echo esc_attr( $quissly_key ); ?>">
						<span class="q-stepper__dot"><?php echo $quissly_index < $done ? '&#10003;' : (int) ( $quissly_index + 1 ); ?></span>
						<span><?php echo esc_html( $quissly_label ); ?></span>
					</button>
				</li>
				<?php ++$quissly_index; ?>
			<?php endforeach; ?>
		</ol>
	</div>

	<?php /* ---- Step 1: Your details ---- */ ?>
	<section data-q-step="details" hidden>
		<form data-q-connect novalidate>
			<div class="q-grid">
				<div class="q-card">
					<?php /* translators: 1: step, 2: steps in all. */ ?>
					<div class="q-kicker"><?php echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'quissly-for-woocommerce' ), 1, 3 ) ); ?></div>
					<h2><?php esc_html_e( 'Your details', 'quissly-for-woocommerce' ); ?></h2>
					<p class="q-desc"><?php esc_html_e( 'We use this email for your Quissly account and billing notifications.', 'quissly-for-woocommerce' ); ?></p>
					<?php if ( $connected ) : ?>
						<div class="q-field">
							<label for="q-email"><?php esc_html_e( 'Email', 'quissly-for-woocommerce' ); ?></label>
							<input id="q-email" type="email" readonly value="<?php echo esc_attr( (string) Quissly_Settings::get( 'quissly_account_email' ) ); ?>">
							<p class="q-help"><?php esc_html_e( 'Your store is connected to this Quissly account.', 'quissly-for-woocommerce' ); ?></p>
						</div>
					<?php else : ?>
						<div class="q-field">
							<label for="q-email"><?php esc_html_e( 'Email', 'quissly-for-woocommerce' ); ?></label>
							<input id="q-email" name="email" type="email" required autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>">
							<p class="q-help"><?php esc_html_e( 'Account and billing emails go here. This can\'t be changed later.', 'quissly-for-woocommerce' ); ?></p>
							<p class="q-help q-help--error" data-q-field-error="email" hidden></p>
						</div>
						<div class="q-field">
							<label for="q-store-name"><?php esc_html_e( 'Store name', 'quissly-for-woocommerce' ); ?></label>
							<input id="q-store-name" name="store_name" type="text" maxlength="100" value="<?php echo esc_attr( $quissly_store_name ); ?>" placeholder="<?php echo esc_attr( $domain ); ?>">
							<?php /* translators: %s: the store's domain. */ ?>
							<p class="q-help"><?php echo esc_html( sprintf( __( 'Letters, numbers, spaces and hyphens. Your Quissly workspace is named after it; left empty, it is named after %s.', 'quissly-for-woocommerce' ), $domain ) ); ?></p>
							<p class="q-help q-help--error" data-q-field-error="store_name" hidden></p>
						</div>
						<div class="q-field">
							<label for="q-description"><?php esc_html_e( 'Description', 'quissly-for-woocommerce' ); ?> <span class="q-optional"><?php esc_html_e( '(optional)', 'quissly-for-woocommerce' ); ?></span></label>
							<textarea id="q-description" name="description" rows="3" maxlength="500"><?php echo esc_textarea( $draft ); ?></textarea>
							<p class="q-help"><?php esc_html_e( 'Drafted from your store. A short note on what you sell, so Quissly and your team recognise this workspace.', 'quissly-for-woocommerce' ); ?></p>
						</div>
						<p class="q-help"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Quissly_Admin::PAGE_SETTINGS . '&quissly_manual=1' ) ); ?>"><?php esc_html_e( 'Already have a token? Paste it manually.', 'quissly-for-woocommerce' ); ?></a></p>
					<?php endif; ?>
					<div class="q-notice q-notice--error" data-q-error hidden></div>
				</div>
				<?php if ( ! $connected ) : ?>
				<aside class="q-card q-card--side">
					<div class="q-kicker"><?php esc_html_e( 'Preview', 'quissly-for-woocommerce' ); ?></div>
					<div class="q-preview">
						<div class="q-preview__kicker"><?php esc_html_e( 'Workspace', 'quissly-for-woocommerce' ); ?></div>
						<div class="q-preview__name" data-q-preview="store_name" data-fallback="<?php echo esc_attr( $domain ); ?>"><?php echo esc_html( '' !== $quissly_store_name ? $quissly_store_name : $domain ); ?></div>
						<p class="q-preview__desc" data-q-preview="description" data-fallback="<?php esc_attr_e( 'No description yet.', 'quissly-for-woocommerce' ); ?>"><?php echo esc_html( '' !== $draft ? $draft : __( 'No description yet.', 'quissly-for-woocommerce' ) ); ?></p>
					</div>
					<p class="q-side-foot"><?php esc_html_e( 'Nothing is charged and nothing is published until you say so.', 'quissly-for-woocommerce' ); ?></p>
				</aside>
				<?php else : ?>
				<aside class="q-card q-card--side">
					<div class="q-kicker"><?php esc_html_e( 'What happens next', 'quissly-for-woocommerce' ); ?></div>
					<ul class="q-next">
						<li>
							<strong><?php esc_html_e( 'Pick a plan', 'quissly-for-woocommerce' ); ?></strong>
							<span><?php esc_html_e( 'Start free, or try a paid plan for 14 days.', 'quissly-for-woocommerce' ); ?></span>
						</li>
						<li>
							<strong><?php esc_html_e( 'Your catalog syncs', 'quissly-for-woocommerce' ); ?></strong>
							<span><?php esc_html_e( 'Your products are sent to Quissly in the background.', 'quissly-for-woocommerce' ); ?></span>
						</li>
						<li>
							<strong><?php esc_html_e( 'Go live', 'quissly-for-woocommerce' ); ?></strong>
							<span><?php esc_html_e( 'Switch Quissly search on once your catalog is ready.', 'quissly-for-woocommerce' ); ?></span>
						</li>
					</ul>
					<p class="q-side-foot"><?php esc_html_e( 'Nothing is charged and nothing is published until you say so.', 'quissly-for-woocommerce' ); ?></p>
				</aside>
				<?php endif; ?>
			</div>
			<div class="q-actionbar">
				<span class="q-actionbar__note">
					<?php echo esc_html( $connected ? __( 'Connected to Quissly.', 'quissly-for-woocommerce' ) : __( 'One click creates your Quissly account and connects this store.', 'quissly-for-woocommerce' ) ); ?>
				</span>
				<?php if ( $connected ) : ?>
					<button type="button" class="q-btn" data-q-forward="plan"><?php esc_html_e( 'Continue', 'quissly-for-woocommerce' ); ?></button>
				<?php else : ?>
					<button type="submit" class="q-btn" data-q-connect-submit><?php esc_html_e( 'Connect & continue', 'quissly-for-woocommerce' ); ?></button>
				<?php endif; ?>
			</div>
		</form>
	</section>

	<?php if ( $connected ) : ?>
	<?php /* ---- Step 2: Choose a plan ---- */ ?>
	<section data-q-step="plan" hidden>
		<div class="q-card q-card--plan">
			<div class="q-plan-head">
				<div>
					<?php /* translators: 1: step, 2: steps in all. */ ?>
					<div class="q-kicker"><?php echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'quissly-for-woocommerce' ), 2, 3 ) ); ?></div>
					<h2><?php esc_html_e( 'Choose a plan', 'quissly-for-woocommerce' ); ?></h2>
				</div>
				<?php if ( null !== $plans && null === $plans['current'] ) : ?>
					<div class="q-cadence" role="radiogroup" aria-label="<?php esc_attr_e( 'Billing', 'quissly-for-woocommerce' ); ?>">
						<button type="button" role="radio" data-q-cadence="monthly" aria-checked="true">
							<strong><?php esc_html_e( 'Monthly', 'quissly-for-woocommerce' ); ?></strong>
							<small><?php esc_html_e( 'Pay as you go', 'quissly-for-woocommerce' ); ?></small>
						</button>
						<button type="button" role="radio" data-q-cadence="annual" aria-checked="false">
							<strong><?php esc_html_e( 'Annual', 'quissly-for-woocommerce' ); ?></strong>
							<small data-q-saving></small>
						</button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( null === $plans ) : ?>
				<div class="q-notice q-notice--warn"><?php esc_html_e( 'Quissly\'s plans could not be loaded just now. You can carry on and choose a plan later in the Quissly Admin Panel.', 'quissly-for-woocommerce' ); ?></div>
			<?php elseif ( null !== $plans['current'] ) : ?>
				<?php /* translators: %s: plan name. */ ?>
				<div class="q-notice"><?php echo esc_html( sprintf( __( 'Your store is on %s. You can change plans any time in the Quissly Admin Panel.', 'quissly-for-woocommerce' ), $plans['current']['name'] ) ); ?></div>
			<?php else : ?>
				<?php if ( ! $plans['billing_open'] ) : ?>
					<div class="q-notice q-notice--warn"><?php esc_html_e( 'Paid plans open soon. Start on the free plan now and upgrade any time.', 'quissly-for-woocommerce' ); ?></div>
				<?php endif; ?>
				<div class="q-tabs" role="tablist">
					<button type="button" role="tab" data-q-tab="qsearch" aria-selected="true"><?php esc_html_e( 'Search', 'quissly-for-woocommerce' ); ?></button>
					<button type="button" role="tab" data-q-tab="qchat" aria-selected="false"><?php esc_html_e( 'Chat', 'quissly-for-woocommerce' ); ?></button>
				</div>
				<div data-q-plan-picker>
					<?php foreach ( $plans['families'] as $quissly_family => $quissly_cards ) : ?>
						<div data-q-plans="<?php echo esc_attr( $quissly_family ); ?>">
							<div class="q-plans" role="radiogroup" data-q-view="cards" aria-label="<?php esc_attr_e( 'Plans', 'quissly-for-woocommerce' ); ?>">
								<?php foreach ( $quissly_cards as $quissly_card ) : ?>
									<div class="q-plan<?php echo $quissly_card['popular'] ? ' is-popular' : ''; ?>" <?php echo $this->plan_attributes( $quissly_card ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in plan_attributes(). ?>>
										<?php if ( $quissly_card['disabled'] ) : ?>
											<span class="q-plan__ribbon q-plan__ribbon--soon"><?php esc_html_e( 'Opens soon', 'quissly-for-woocommerce' ); ?></span>
										<?php elseif ( $quissly_card['popular'] ) : ?>
											<span class="q-plan__ribbon"><?php esc_html_e( 'Most popular', 'quissly-for-woocommerce' ); ?></span>
										<?php endif; ?>
										<div class="q-plan__head">
											<span><?php echo esc_html( $quissly_card['name'] ); ?></span>
											<span class="q-plan__radio" aria-hidden="true"></span>
										</div>
										<div class="q-plan__price" data-q-monthly><?php echo esc_html( $quissly_card['monthly'] ); ?> <small><?php esc_html_e( '/mo', 'quissly-for-woocommerce' ); ?></small></div>
										<div class="q-plan__price" data-q-annual><?php echo esc_html( $quissly_card['annual_month'] ); ?> <small><?php esc_html_e( '/mo', 'quissly-for-woocommerce' ); ?></small></div>
										<div class="q-plan__trial" data-q-monthly><?php echo esc_html( $quissly_card['monthly_note'] ); ?></div>
										<div class="q-plan__trial" data-q-annual><?php echo esc_html( $quissly_card['annual_note'] ); ?></div>
										<div class="q-plan__figures">
											<?php foreach ( $quissly_card['figures'] as $quissly_figure ) : ?>
												<div>
													<strong><?php echo esc_html( $quissly_figure[0] ); ?></strong>
													<span><?php echo esc_html( $quissly_figure[1] ); ?></span>
												</div>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
							<?php /* "Compare all features" swaps the cards for this table (direction 3a). */ ?>
							<div class="q-compare-wrap" data-q-view="table" hidden>
								<table class="q-compare">
									<thead>
										<tr>
											<th scope="col" class="q-compare__corner"><?php esc_html_e( 'Compare plans', 'quissly-for-woocommerce' ); ?></th>
											<?php foreach ( $quissly_cards as $quissly_card ) : ?>
												<th scope="col">
													<button type="button" class="q-compare__plan" <?php echo $this->plan_attributes( $quissly_card ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in plan_attributes(). ?>>
														<span class="q-compare__name"><span class="q-plan__radio" aria-hidden="true"></span><?php echo esc_html( $quissly_card['name'] ); ?></span>
														<span class="q-compare__price" data-q-monthly><?php echo esc_html( $quissly_card['monthly'] ); ?> <small><?php esc_html_e( '/mo', 'quissly-for-woocommerce' ); ?></small></span>
														<span class="q-compare__price" data-q-annual><?php echo esc_html( $quissly_card['annual_month'] ); ?> <small><?php esc_html_e( '/mo', 'quissly-for-woocommerce' ); ?></small></span>
														<span class="q-compare__note"><?php echo esc_html( $quissly_card['disabled'] ? __( 'Opens soon', 'quissly-for-woocommerce' ) : $quissly_card['monthly_note'] ); ?></span>
													</button>
												</th>
											<?php endforeach; ?>
										</tr>
									</thead>
									<tbody>
										<?php
										$quissly_compare_rows = array(
											array( 'qchat' === $quissly_family ? __( 'AI messages / month', 'quissly-for-woocommerce' ) : __( 'Searches / month', 'quissly-for-woocommerce' ), 'usage' ),
											array( __( 'Extra requests', 'quissly-for-woocommerce' ), 'extra' ),
											array( __( 'Billed yearly', 'quissly-for-woocommerce' ), 'annual_total' ),
											array( __( 'Free trial', 'quissly-for-woocommerce' ), 'trial_label' ),
										);
										?>
										<?php foreach ( $quissly_compare_rows as $quissly_compare ) : ?>
											<tr>
												<th scope="row"><?php echo esc_html( $quissly_compare[0] ); ?></th>
												<?php foreach ( $quissly_cards as $quissly_card ) : ?>
													<?php
													$quissly_value = 'usage' === $quissly_compare[1] ? $quissly_card['figures'][0][0]
														: ( 'extra' === $quissly_compare[1] ? $quissly_card['figures'][1][0] : $quissly_card[ $quissly_compare[1] ] );
													?>
													<td><?php echo esc_html( $quissly_value ); ?></td>
												<?php endforeach; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="q-compare-row">
					<button type="button" class="q-btn q-btn--quiet q-btn--small" data-q-compare><?php esc_html_e( 'Compare all features', 'quissly-for-woocommerce' ); ?></button>
				</div>
				<div class="q-notice" data-q-pay hidden>
					<?php esc_html_e( 'Finish the payment in the tab that opened. This page moves on by itself once Quissly has it.', 'quissly-for-woocommerce' ); ?>
					<a href="#" target="_blank" rel="noopener noreferrer" data-q-pay-open><?php esc_html_e( 'Open the payment page again', 'quissly-for-woocommerce' ); ?></a>
					&middot;
					<button type="button" class="q-link" data-q-pay-check><?php esc_html_e( 'I have paid', 'quissly-for-woocommerce' ); ?></button>
				</div>
			<?php endif; ?>
			<div class="q-notice q-notice--error" data-q-error hidden></div>
		</div>
		<div class="q-actionbar">
			<button type="button" class="q-btn q-btn--quiet" data-q-back="details"><?php esc_html_e( 'Back', 'quissly-for-woocommerce' ); ?></button>
			<?php if ( null === $plans ) : ?>
				<span class="q-actionbar__note"></span>
				<button type="button" class="q-btn" data-q-plan-op="later"><?php esc_html_e( 'Continue without a plan', 'quissly-for-woocommerce' ); ?></button>
			<?php elseif ( null !== $plans['current'] ) : ?>
				<span class="q-actionbar__note"><?php echo esc_html( $plans['current']['name'] ); ?></span>
				<button type="button" class="q-btn" data-q-plan-op="keep"><?php esc_html_e( 'Continue', 'quissly-for-woocommerce' ); ?></button>
			<?php else : ?>
				<span class="q-actionbar__note" data-q-plan-note></span>
				<button type="button" class="q-btn" data-q-plan-submit disabled></button>
			<?php endif; ?>
		</div>
	</section>

	<?php /* ---- Step 3: Go live ---- */ ?>
	<section data-q-step="golive" hidden>
		<div class="q-grid q-grid--golive">
			<div class="q-card q-card--golive">
				<?php /* translators: 1: step, 2: steps in all. */ ?>
				<div class="q-kicker"><?php echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'quissly-for-woocommerce' ), 3, 3 ) ); ?></div>
				<h2><?php esc_html_e( 'Go live', 'quissly-for-woocommerce' ); ?></h2>
				<p class="q-desc"><?php esc_html_e( 'Your catalog is on its way to Quissly. Once it is ready, Finish Setup switches Quissly search on for your shoppers - or save now and switch it on later.', 'quissly-for-woocommerce' ); ?></p>
				<ul class="q-golive-list">
					<li><span class="q-disc" aria-hidden="true"></span><div>
						<?php esc_html_e( 'Quissly answers your store\'s search', 'quissly-for-woocommerce' ); ?>
						<span><?php esc_html_e( 'Your theme still shows the results, as it does today.', 'quissly-for-woocommerce' ); ?></span>
					</div></li>
					<li><span class="q-disc" aria-hidden="true"></span><div>
						<?php esc_html_e( 'Your own search stays as the safety net', 'quissly-for-woocommerce' ); ?>
						<span><?php esc_html_e( 'If Quissly ever cannot answer, WooCommerce\'s search does.', 'quissly-for-woocommerce' ); ?></span>
					</div></li>
					<li><span class="q-disc" aria-hidden="true"></span><div>
						<?php esc_html_e( 'Everything else is in Configuration', 'quissly-for-woocommerce' ); ?>
						<span><?php esc_html_e( 'Chat, voice and image search, and the search bar.', 'quissly-for-woocommerce' ); ?></span>
					</div></li>
				</ul>
				<div class="q-notice q-notice--error" data-q-error hidden></div>
			</div>
			<div class="q-card q-card--warm">
				<div class="q-status-head">
					<div class="q-kicker q-kicker--quiet"><?php esc_html_e( 'Quissly account setup', 'quissly-for-woocommerce' ); ?></div>
					<span class="q-chip" data-q-chip><?php esc_html_e( 'Setting up...', 'quissly-for-woocommerce' ); ?></span>
				</div>
				<p class="q-status-body"><?php esc_html_e( 'Running in the background. Finish Setup unlocks when it is done - you can keep working on the left.', 'quissly-for-woocommerce' ); ?></p>
				<ol class="q-rows">
					<?php foreach ( $quissly_rows as $quissly_key => $quissly_label ) : ?>
						<li class="q-row" data-q-row="<?php echo esc_attr( $quissly_key ); ?>" data-state="pending">
							<span class="q-disc" aria-hidden="true"></span>
							<span class="q-row__label"><?php echo esc_html( $quissly_label ); ?> <small data-q-row-detail hidden></small></span>
							<span class="q-row__state" data-q-row-state><?php esc_html_e( 'Waiting', 'quissly-for-woocommerce' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
				<p class="q-status-foot">
					<button type="button" class="q-btn q-btn--quiet q-btn--small" data-q-retry hidden><?php esc_html_e( 'Start the sync again', 'quissly-for-woocommerce' ); ?></button>
					<?php esc_html_e( 'Usually a few minutes. This page updates itself - no refresh needed.', 'quissly-for-woocommerce' ); ?>
				</p>
			</div>
		</div>
		<div class="q-actionbar">
			<button type="button" class="q-btn q-btn--quiet" data-q-back="plan"><?php esc_html_e( 'Back', 'quissly-for-woocommerce' ); ?></button>
			<span class="q-actionbar__note"><?php esc_html_e( 'Finish Setup unlocks when background setup completes.', 'quissly-for-woocommerce' ); ?></span>
			<?php /* The Shopify app's "Save changes": done for now, QSearch switched on later. */ ?>
			<button type="button" class="q-btn q-btn--quiet" data-q-save hidden disabled><?php esc_html_e( 'Save changes', 'quissly-for-woocommerce' ); ?></button>
			<button type="button" class="q-btn" data-q-finish disabled><?php esc_html_e( 'Finish Setup', 'quissly-for-woocommerce' ); ?></button>
		</div>
	</section>
	<?php endif; ?>
</div>
