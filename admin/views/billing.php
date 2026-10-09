<?php
/**
 * Quissly Billing - the store's plans, usage and invoices, laid out as the Shopify app's
 * Settings billing column (assets/css/quissly-billing.css). assets/js/quissly-billing.js reads
 * it by its data-q-* attributes. The same markup as quissly-for-magento's billing.phtml.
 *
 * @package Quissly_For_WooCommerce
 *
 * @var array|null $view   Quissly_Billing::view().
 * @var array      $config The script configuration.
 * @var string     $flash  The last action's message, once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="q-billing" data-q-billing data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
	<?php if ( null === $view ) : ?>
		<div class="q-banner q-banner--critical">
			<?php esc_html_e( 'Quissly\'s billing could not be reached just now. Try again in a minute.', 'quissly-for-woocommerce' ); ?>
		</div>
	<?php else : ?>
		<div class="q-settings" data-q-settings>
			<div class="q-settings__col">
				<?php if ( '' !== $flash ) : ?>
					<div class="q-banner q-banner--success"><?php echo esc_html( $flash ); ?></div>
				<?php endif; ?>
				<?php if ( ! $view['billing_open'] ) : ?>
					<div class="q-banner q-banner--warning">
						<?php esc_html_e( 'Paid plans open soon. The free search plan is available now.', 'quissly-for-woocommerce' ); ?>
					</div>
				<?php endif; ?>

				<?php foreach ( $view['families'] as $family => $item ) : ?>
					<?php $plan = $item['plan']; ?>
					<section class="q-section" data-q-family="<?php echo esc_attr( $family ); ?>">
						<div class="q-planhead">
							<div class="q-planhead__main">
								<?php /* translators: %s: Search or Chat. */ ?>
								<p class="q-kicker"><?php echo esc_html( sprintf( __( '%s plan', 'quissly-for-woocommerce' ), $item['label'] ) ); ?></p>
								<span class="q-planhead__name">
									<?php echo esc_html( null !== $plan ? $plan['name'] : __( 'No plan selected', 'quissly-for-woocommerce' ) ); ?>
								</span>
								<span class="q-planhead__price">
									<?php
									if ( null !== $plan ) {
										echo esc_html( $plan['meta'] );
									} elseif ( 'qchat' === $family ) {
										esc_html_e( 'Add the Quissly chat assistant to your store.', 'quissly-for-woocommerce' );
									} else {
										esc_html_e( 'Quissly search needs a plan to answer your shoppers.', 'quissly-for-woocommerce' );
									}
									?>
								</span>
							</div>
							<?php if ( null !== $plan ) : ?>
								<span class="q-chip is-<?php echo esc_attr( $plan['chip'] ); ?>">
									<span class="q-chip__dot" aria-hidden="true"></span>
									<?php echo esc_html( $plan['status_label'] ); ?>
								</span>
							<?php endif; ?>
						</div>

						<?php foreach ( null !== $plan ? $plan['banners'] : array() as $banner ) : ?>
							<div class="q-banner q-banner--<?php echo esc_attr( $banner['tone'] ); ?>">
								<span><?php echo esc_html( $banner['text'] ); ?></span>
								<?php if ( ! empty( $banner['op'] ) ) : ?>
									<button type="button" class="q-button" data-q-op="<?php echo esc_attr( $banner['op'] ); ?>" data-id="<?php echo esc_attr( $plan['id'] ); ?>">
										<?php echo esc_html( $banner['action'] ); ?>
									</button>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>

						<div class="q-stack" data-q-actions>
							<?php if ( null === $plan || $plan['can_change'] || $plan['can_upgrade_free'] ) : ?>
								<button type="button" class="q-button q-button--primary" data-q-open="<?php echo esc_attr( $family ); ?>">
									<?php
									if ( null === $plan ) {
										esc_html_e( 'Choose a plan', 'quissly-for-woocommerce' );
									} elseif ( $plan['can_upgrade_free'] ) {
										esc_html_e( 'Upgrade plan', 'quissly-for-woocommerce' );
									} else {
										esc_html_e( 'Change plan', 'quissly-for-woocommerce' );
									}
									?>
								</button>
							<?php endif; ?>
							<?php if ( null !== $plan && $plan['can_cancel'] ) : ?>
								<button type="button" class="q-button q-button--tertiary q-button--critical" data-q-op="cancel_confirm"
										data-id="<?php echo esc_attr( $plan['id'] ); ?>"
										data-summary="<?php esc_attr_e( 'Your plan stays until the end of the paid period, then ends. Nothing is refunded, and you can keep it any time before then.', 'quissly-for-woocommerce' ); ?>">
									<?php esc_html_e( 'Cancel subscription', 'quissly-for-woocommerce' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( null !== $plan && $plan['can_refund'] ) : ?>
								<button type="button" class="q-button q-button--tertiary" data-q-op="refund_preview" data-id="<?php echo esc_attr( $plan['id'] ); ?>">
									<?php esc_html_e( 'Request a refund', 'quissly-for-woocommerce' ); ?>
								</button>
							<?php endif; ?>
						</div>

						<?php
						// The plans, shown in place when the merchant asks to change or start one.
						// From a paid plan the way to Free is cancelling: change-plan moves between
						// paid plans only, and monthly <-> yearly is refused, so the cadence switch
						// is offered only when a plan is being started.
						$changing = null !== $plan && ! $plan['can_upgrade_free'];
						$shown    = array_filter(
							$item['cards'],
							static function ( $c ) use ( $changing ) {
								return ! $c['free'] || ! $changing;
							}
						);
						?>
						<div class="q-stack q-stack--block q-stack--large" data-q-picker
							data-sub="<?php echo esc_attr( $changing ? $plan['id'] : '' ); ?>"
							data-current="<?php echo esc_attr( null !== $plan ? $plan['plan_id'] : '' ); ?>" hidden>
							<?php if ( ! $changing ) : ?>
								<div class="q-stack">
									<div class="q-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Billing cadence', 'quissly-for-woocommerce' ); ?>">
										<button type="button" class="q-seg__btn" role="radio" aria-checked="true" data-q-cycle="monthly"><?php esc_html_e( 'Monthly', 'quissly-for-woocommerce' ); ?></button>
										<button type="button" class="q-seg__btn" role="radio" aria-checked="false" data-q-cycle="annual"><?php esc_html_e( 'Annual', 'quissly-for-woocommerce' ); ?></button>
									</div>
									<?php if ( $view['discount_pct'] > 0 ) : ?>
										<?php /* translators: %d: annual discount percentage. */ ?>
										<span class="q-pill q-pill--accent"><?php echo esc_html( sprintf( __( 'Annual saves %d%%', 'quissly-for-woocommerce' ), $view['discount_pct'] ) ); ?></span>
									<?php endif; ?>
								</div>
							<?php endif; ?>

							<?php /* translators: %s: Search or Chat. */ ?>
							<div class="q-plans<?php echo 3 === count( $shown ) ? ' q-plans--three' : ''; ?>" role="radiogroup" aria-label="<?php echo esc_attr( sprintf( __( '%s plans', 'quissly-for-woocommerce' ), $item['label'] ) ); ?>">
								<?php foreach ( $shown as $card ) : ?>
									<button type="button" role="radio" aria-checked="false"
										class="q-plan<?php echo $card['popular'] ? ' is-popular' : ''; ?>"
										data-q-pick="<?php echo esc_attr( $card['id'] ); ?>"
										data-free="<?php echo $card['free'] ? '1' : '0'; ?>"
										<?php if ( null !== $card['topup'] && ! $changing ) : ?>
											<?php foreach ( array( 'min', 'max', 'default', 'min_text', 'max_text', 'rate', 'hint' ) as $at_key ) : ?>
												data-at-<?php echo esc_attr( str_replace( '_', '-', $at_key ) ); ?>="<?php echo esc_attr( $card['topup'][ $at_key ] ); ?>"
											<?php endforeach; ?>
										<?php endif; ?>
										<?php echo $card['disabled'] ? 'aria-disabled="true"' : ''; ?>>
										<?php if ( $card['disabled'] ) : ?>
											<span class="q-pill q-pill--warn q-plan__ribbon"><?php esc_html_e( 'Opens soon', 'quissly-for-woocommerce' ); ?></span>
										<?php elseif ( $card['popular'] ) : ?>
											<span class="q-pill q-pill--accent q-plan__ribbon"><?php esc_html_e( 'Most popular', 'quissly-for-woocommerce' ); ?></span>
										<?php endif; ?>
										<span class="q-plan__head">
											<span class="q-plan__name"><?php echo esc_html( $card['name'] ); ?></span>
											<span class="q-plan__radio" aria-hidden="true"></span>
										</span>
										<span class="q-plan__price">
											<span class="q-plan__amount" data-q-monthly><?php echo esc_html( $card['monthly'] ); ?></span>
											<span class="q-plan__amount" data-q-annual hidden><?php echo esc_html( $card['annual_month'] ); ?></span>
											<span class="q-plan__cadence"><?php esc_html_e( '/mo', 'quissly-for-woocommerce' ); ?></span>
										</span>
										<span class="q-plan__note" data-q-monthly><?php echo esc_html( $card['note_month'] ); ?></span>
										<span class="q-plan__note" data-q-annual hidden><?php echo esc_html( $card['note_year'] ); ?></span>
										<?php if ( '' !== $card['trial'] && ! $changing ) : ?>
											<span><span class="q-pill q-pill--brand"><?php echo esc_html( $card['trial'] ); ?></span></span>
										<?php endif; ?>
										<span class="q-plan__feats" role="list">
											<?php foreach ( $card['feats'] as $i => $feat ) : ?>
												<span class="q-plan__feat<?php echo 0 === $i ? ' is-lead' : ''; ?>" role="listitem"><?php echo esc_html( $feat ); ?></span>
											<?php endforeach; ?>
										</span>
									</button>
								<?php endforeach; ?>
							</div>

							<?php if ( ! $changing ) : ?>
								<div class="q-autotopup" data-q-autotopup hidden>
									<span class="q-autotopup__title"><?php esc_html_e( 'Automatic top-up on this plan', 'quissly-for-woocommerce' ); ?></span>
									<label class="q-check">
										<input type="checkbox" data-q-at-toggle>
										<span><?php esc_html_e( 'Top up automatically', 'quissly-for-woocommerce' ); ?></span>
										<span class="q-text-quiet" data-q-at-rate></span>
									</label>
									<div class="q-field" data-q-at-fields hidden>
										<span class="q-field__label"><?php esc_html_e( 'Max per month', 'quissly-for-woocommerce' ); ?></span>
										<span class="q-money">
											<span class="q-money__prefix">$</span>
											<input type="number" step="1" inputmode="decimal" data-q-at-max aria-label="<?php esc_attr_e( 'Max per month', 'quissly-for-woocommerce' ); ?>">
										</span>
										<span class="q-field__hint" data-q-at-hint></span>
									</div>
									<p class="q-text-quiet"><?php esc_html_e( 'When this month\'s requests are about to run out, Quissly buys one more block on your card. Off, requests stop at your plan\'s limit until next month.', 'quissly-for-woocommerce' ); ?></p>
									<p class="q-field-error" data-q-at-error hidden></p>
								</div>
							<?php endif; ?>

							<p class="q-text-quiet" data-q-same hidden>
								<?php esc_html_e( 'This is the plan you are on. Pick a different one to change it.', 'quissly-for-woocommerce' ); ?>
							</p>

							<div class="q-stack">
								<button type="button" class="q-button q-button--primary" data-q-confirm disabled><?php esc_html_e( 'Confirm plan', 'quissly-for-woocommerce' ); ?></button>
								<button type="button" class="q-button q-button--tertiary" data-q-close><?php esc_html_e( 'Cancel', 'quissly-for-woocommerce' ); ?></button>
							</div>
						</div>
					</section>
				<?php endforeach; ?>

				<?php if ( array() !== $view['meters'] ) : ?>
					<section class="q-section">
						<h2 class="q-section__heading"><?php esc_html_e( 'Usage', 'quissly-for-woocommerce' ); ?></h2>
						<div class="q-usagepanel">
							<div class="q-usagepanel__meters">
								<?php foreach ( $view['meters'] as $meter ) : ?>
									<div class="q-usage">
										<div class="q-usage__head">
											<span class="q-usage__label"><?php echo esc_html( $meter['label'] ); ?></span>
											<span class="q-usage__value">
												<strong><?php echo esc_html( $meter['used'] ); ?></strong>
												<?php if ( '' !== $meter['of'] ) : ?>
													<span class="q-usage__limit">/ <?php echo esc_html( $meter['of'] ); ?></span>
													<span class="q-usage__left<?php echo $meter['out'] ? ' is-out' : ''; ?>">· <?php echo esc_html( $meter['left'] ); ?></span>
												<?php endif; ?>
											</span>
										</div>
										<div class="q-usage__track <?php echo esc_attr( $meter['tone'] ); ?>" role="progressbar"
											aria-label="<?php echo esc_attr( $meter['label'] ); ?>" aria-valuemin="0"
											aria-valuemax="<?php echo (int) $meter['of_raw']; ?>"
											aria-valuenow="<?php echo (int) min( $meter['used_raw'], $meter['of_raw'] ); ?>">
											<div class="q-usage__fill" style="width: <?php echo esc_attr( (string) (float) $meter['pct'] ); ?>%"></div>
										</div>
										<?php if ( '' !== $meter['note'] ) : ?>
											<p class="q-usage__note"><?php echo esc_html( $meter['note'] ); ?></p>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
							<?php if ( '' !== $view['window'] ) : ?>
								<p class="q-usagepanel__window"><?php echo esc_html( $view['window'] ); ?></p>
							<?php endif; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( array() !== $view['extras'] ) : ?>
					<section class="q-section">
						<h2 class="q-section__heading"><?php esc_html_e( 'Extra requests', 'quissly-for-woocommerce' ); ?></h2>
						<div class="q-grid">
							<?php foreach ( $view['extras'] as $extra ) : ?>
								<?php if ( null !== $extra['plan']['extra'] ) : ?>
									<div class="q-metric">
										<span class="q-metric__value"><?php echo esc_html( $extra['plan']['extra']['price'] ); ?></span>
										<?php /* translators: 1: QSearch or QChat, 2: number of requests. */ ?>
										<span class="q-metric__label"><?php echo esc_html( sprintf( __( '%1$s · %2$s extra requests', 'quissly-for-woocommerce' ), $extra['label'], $extra['plan']['extra']['requests'] ) ); ?></span>
									</div>
								<?php endif; ?>
								<div class="q-metric">
									<span class="q-metric__value"><?php echo esc_html( number_format_i18n( $extra['extra'] ) ); ?></span>
									<?php /* translators: %s: QSearch or QChat. */ ?>
									<span class="q-metric__label"><?php echo esc_html( sprintf( __( '%s extra this month', 'quissly-for-woocommerce' ), $extra['label'] ) ); ?></span>
									<span class="q-metric__hint"><?php esc_html_e( 'Unused ones carry over', 'quissly-for-woocommerce' ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="q-text-quiet">
							<?php esc_html_e( 'Extra requests are bought in blocks and charged to your card at once. They carry over to next month while your plan continues.', 'quissly-for-woocommerce' ); ?>
						</p>
						<?php
						$buyable = array_filter(
							$view['extras'],
							static function ( $e ) {
								return $e['plan']['can_topup'];
							}
						);
						?>
						<?php
						$automatic = array_filter(
							$view['extras'],
							static function ( $e ) {
								return $e['plan']['can_auto_topup'] && isset( $e['plan']['auto_topup']['min'] );
							}
						);
						?>
						<?php foreach ( $automatic as $extra ) : ?>
							<?php $auto = $extra['plan']['auto_topup']; ?>
							<div class="q-divider"></div>
							<div class="q-autotopup" data-q-at-form data-id="<?php echo esc_attr( $extra['plan']['id'] ); ?>">
								<?php /* translators: %s: QSearch or QChat. */ ?>
								<span class="q-autotopup__title"><?php echo esc_html( count( $automatic ) > 1 ? sprintf( __( '%s: automatic top-up', 'quissly-for-woocommerce' ), $extra['label'] ) : __( 'Automatic top-up', 'quissly-for-woocommerce' ) ); ?></span>
								<span class="q-text-quiet"><?php echo esc_html( $auto['label'] ); ?></span>
								<label class="q-check">
									<input type="checkbox" data-q-at-toggle<?php echo $auto['on'] ? ' checked' : ''; ?>>
									<span><?php esc_html_e( 'Top up automatically', 'quissly-for-woocommerce' ); ?></span>
									<span class="q-text-quiet"><?php echo esc_html( $auto['rate'] ); ?></span>
								</label>
								<div class="q-field" data-q-at-fields hidden>
									<span class="q-field__label"><?php esc_html_e( 'Max per month', 'quissly-for-woocommerce' ); ?></span>
									<span class="q-money">
										<span class="q-money__prefix">$</span>
										<input type="number" step="1" inputmode="decimal" data-q-at-max aria-label="<?php esc_attr_e( 'Max per month', 'quissly-for-woocommerce' ); ?>"
											min="<?php echo esc_attr( $auto['min'] ); ?>" max="<?php echo esc_attr( $auto['max'] ); ?>"
											data-min-text="<?php echo esc_attr( $auto['min_text'] ); ?>" data-max-text="<?php echo esc_attr( $auto['max_text'] ); ?>"
											value="<?php echo esc_attr( $auto['default'] ); ?>">
									</span>
									<span class="q-field__hint"><?php echo esc_html( $auto['hint'] ); ?></span>
								</div>
								<p class="q-field-error" data-q-at-error hidden></p>
								<div><button type="button" class="q-button" data-q-at-save><?php esc_html_e( 'Save automatic top-up', 'quissly-for-woocommerce' ); ?></button></div>
							</div>
						<?php endforeach; ?>
						<?php if ( array() !== $buyable ) : ?>
							<div class="q-divider"></div>
							<div class="q-stack">
								<?php foreach ( $buyable as $extra ) : ?>
									<button type="button" class="q-button" data-q-op="topup_preview" data-id="<?php echo esc_attr( $extra['plan']['id'] ); ?>">
										<?php
										/* translators: 1: QSearch or QChat, 2: "Buy 5,000 more for $10". */
										echo esc_html( count( $buyable ) > 1 ? sprintf( __( '%1$s: %2$s', 'quissly-for-woocommerce' ), $extra['label'], $extra['plan']['extra']['label'] ) : $extra['plan']['extra']['label'] );
										?>
									</button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>
				<?php endif; ?>
			</div>

			<div class="q-settings__col">
				<section class="q-section">
					<h2 class="q-section__heading"><?php esc_html_e( 'Invoices', 'quissly-for-woocommerce' ); ?></h2>
					<?php if ( null === $view['invoices'] ) : ?>
						<p class="q-text-quiet"><?php esc_html_e( 'Invoices could not be loaded just now.', 'quissly-for-woocommerce' ); ?></p>
					<?php elseif ( array() === $view['invoices'] ) : ?>
						<p class="q-text-quiet"><?php esc_html_e( 'No payments yet.', 'quissly-for-woocommerce' ); ?></p>
					<?php else : ?>
						<div class="q-list" role="list">
							<?php foreach ( $view['invoices'] as $invoice ) : ?>
								<div class="q-list__row" role="listitem">
									<div class="q-list__main">
										<?php /* translators: 1: amount, 2: status. */ ?>
										<span class="q-list__label"><?php echo esc_html( sprintf( __( '%1$s · %2$s', 'quissly-for-woocommerce' ), $invoice['amount'], $invoice['status'] ) ); ?></span>
										<?php /* translators: 1: date, 2: billing period. */ ?>
										<span class="q-list__note"><?php echo esc_html( sprintf( __( '%1$s · for %2$s', 'quissly-for-woocommerce' ), $invoice['date'], $invoice['period'] ) ); ?></span>
									</div>
									<?php if ( $invoice['pdf'] ) : ?>
										<div class="q-list__aside">
											<button type="button" class="q-button" data-q-op="invoice_pdf" data-id="<?php echo esc_attr( $invoice['id'] ); ?>"><?php esc_html_e( 'PDF', 'quissly-for-woocommerce' ); ?></button>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>

				<?php
				$paid = array_filter(
					array_column( $view['families'], 'plan' ),
					static function ( $p ) {
						return $p && $p['can_card'];
					}
				);
				?>
				<section class="q-section">
					<h2 class="q-section__heading"><?php esc_html_e( 'Payment method', 'quissly-for-woocommerce' ); ?></h2>
					<p class="q-text-quiet">
						<?php esc_html_e( 'Payments are processed by Paddle, Quissly\'s merchant of record. Your card is never stored here.', 'quissly-for-woocommerce' ); ?>
					</p>
					<?php if ( array() !== $paid ) : ?>
						<div class="q-list" role="list">
							<?php foreach ( $paid as $paid_plan ) : ?>
								<div class="q-list__row" role="listitem">
									<div class="q-list__main">
										<span class="q-list__label"><?php echo esc_html( $paid_plan['name'] ); ?></span>
										<span class="q-list__note"><?php esc_html_e( 'Card on file with Paddle', 'quissly-for-woocommerce' ); ?></span>
									</div>
									<div class="q-list__aside">
										<button type="button" class="q-button" data-q-op="payment_method" data-id="<?php echo esc_attr( $paid_plan['id'] ); ?>"><?php esc_html_e( 'Update card', 'quissly-for-woocommerce' ); ?></button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>

				<p class="q-links">
					<a href="<?php echo esc_url( Quissly_Billing::LINKS['refund'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Refund policy', 'quissly-for-woocommerce' ); ?></a>
					<a href="<?php echo esc_url( Quissly_Billing::LINKS['terms'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Terms of service', 'quissly-for-woocommerce' ); ?></a>
					<a href="<?php echo esc_url( Quissly_Billing::LINKS['privacy'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy', 'quissly-for-woocommerce' ); ?></a>
				</p>
			</div>
		</div>
	<?php endif; ?>

	<div class="q-modal" data-q-modal hidden>
		<div class="q-modal__box" role="dialog" aria-modal="true" aria-labelledby="q-modal-title">
			<h3 class="q-modal__title" id="q-modal-title" data-q-modal-title></h3>
			<div class="q-modal__body">
				<p data-q-modal-body></p>
				<div class="q-claim" data-q-modal-claim hidden>
					<label class="q-field__label" for="q-claim"><?php esc_html_e( 'What happened?', 'quissly-for-woocommerce' ); ?></label>
					<textarea id="q-claim" data-q-claim data-min="<?php echo (int) Quissly_Billing::CLAIM_MIN; ?>" maxlength="<?php echo (int) Quissly_Billing::CLAIM_MAX; ?>" rows="5"></textarea>
					<span class="q-claim__count" data-q-claim-count></span>
				</div>
				<div class="q-banner q-banner--critical" data-q-modal-error hidden></div>
			</div>
			<div class="q-modal__actions">
				<button type="button" class="q-button" data-q-modal-cancel><?php esc_html_e( 'Cancel', 'quissly-for-woocommerce' ); ?></button>
				<button type="button" class="q-button q-button--primary" data-q-modal-ok></button>
			</div>
		</div>
	</div>
</div>
