<div id="sameday-delivery-fields" class="sameday-delivery-fields">
	<h3>Доставка</h3>
	<p class="sameday-delivery-intro">
		Изберете куриер и вариант на доставка. За EasyBox има официален списък с всички автомати в България.
	</p>

	<div class="sameday-provider-grid">
		<?php foreach ( $provider_options as $provider_code => $provider_label ) : ?>
			<?php
			$provider_logo = '';
			$provider_text = $provider_label;

			if ( 'sameday' === $provider_code ) {
				$provider_logo = 'https://reasevt.com/wp-content/uploads/2026/03/logo-easybox-scaled-1.webp';
				$provider_text = 'Доставка със';
			} elseif ( 'speedy' === $provider_code ) {
				$provider_logo = 'https://reasevt.com/wp-content/uploads/2026/03/speedy-logo.png';
				$provider_text = 'Доставка със';
			}
			?>
			<label class="sameday-choice-card">
				<input
					type="radio"
					name="sameday_shipping_provider"
					value="<?php echo esc_attr( $provider_code ); ?>"
					<?php checked( $selection['provider'], $provider_code ); ?>
				/>
				<span class="sameday-provider-card-content">
					<span class="sameday-provider-card-text"><?php echo esc_html( $provider_text ); ?></span>
					<?php if ( ! empty( $provider_logo ) ) : ?>
						<img
							class="sameday-provider-card-logo sameday-provider-card-logo-<?php echo esc_attr( $provider_code ); ?>"
							src="<?php echo esc_url( $provider_logo ); ?>"
							alt="<?php echo esc_attr( $provider_label ); ?>"
							loading="lazy"
						/>
					<?php endif; ?>
				</span>
			</label>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $services_by_provider as $provider_code => $services ) : ?>
		<div
			class="sameday-service-group<?php echo $selection['provider'] === $provider_code ? ' is-active' : ''; ?>"
			data-provider="<?php echo esc_attr( $provider_code ); ?>"
		>
			<?php foreach ( $services as $service_code => $service ) : ?>
				<label class="sameday-choice-row">
					<input
						type="radio"
						name="sameday_shipping_service"
						value="<?php echo esc_attr( $service_code ); ?>"
						<?php checked( $selection['service'], $service_code ); ?>
					/>
					<span><?php echo esc_html( $service['label'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>

	<div class="sameday-easybox-selector<?php echo 'sameday_easybox' === $selection['service'] ? '' : ' is-hidden'; ?>">
		<p class="description">
			Изберете населено място и търсете директно вътре в падащия списък с EasyBox автомати.
		</p>

		<p class="form-row form-row-wide">
			<label for="sameday_easybox_city">
				Населено място <span class="required">*</span>
			</label>
			<select
				name="sameday_easybox_city"
				id="sameday_easybox_city"
				class="select"
				data-selected="<?php echo esc_attr( $selection['easybox_city'] ); ?>"
			>
				<option value="">Зареждане...</option>
			</select>
		</p>

		<p class="form-row form-row-wide">
			<label for="sameday_easybox_location">
				EasyBox автомат <span class="required">*</span>
			</label>
			<select
				name="sameday_easybox_location"
				id="sameday_easybox_location"
				class="select"
				data-selected="<?php echo esc_attr( $selection['easybox_location'] ); ?>"
			>
				<?php if ( $selected_easybox_location ) : ?>
					<option value="<?php echo esc_attr( $selected_easybox_location['id'] ); ?>" selected="selected">
						<?php echo esc_html( $selected_easybox_location['label'] ); ?>
					</option>
				<?php else : ?>
					<option value="">Изберете EasyBox автомат</option>
				<?php endif; ?>
			</select>
			<span id="sameday_easybox_help" class="description">
				Официалният списък се зарежда от Sameday и съдържа всички автомати в България. След отваряне на списъка може да започнете да пишете вътре в него.
			</span>
			<span id="sameday_easybox_selected_meta" class="description">
				<?php if ( $selected_easybox_location ) : ?>
					<?php echo esc_html( $selected_easybox_location['details'] ); ?>
				<?php endif; ?>
			</span>
		</p>
	</div>

	<div class="sameday-door-fields<?php echo 'sameday_door' === $selection['service'] ? '' : ' is-hidden'; ?>">
		<p class="form-row form-row-wide">
			<label for="sameday_door_city">
				Населено място <span class="required">*</span>
			</label>
			<input
				type="text"
				name="sameday_door_city"
				id="sameday_door_city"
				class="input-text"
				value="<?php echo esc_attr( $selection['sameday_door_city'] ); ?>"
				placeholder="Напишете вашият Град или Село"
			/>
		</p>

		<p class="form-row form-row-wide">
			<label for="sameday_door_address">
				Адрес <span class="required">*</span>
			</label>
			<input
				type="text"
				name="sameday_door_address"
				id="sameday_door_address"
				class="input-text"
				value="<?php echo esc_attr( $selection['sameday_door_address'] ); ?>"
				placeholder="Вашият адрес за доставка"
			/>
		</p>
	</div>

	<div class="speedy-location-selector<?php echo ( 'speedy_office' === $selection['service'] || 'speedy_aps' === $selection['service'] ) ? '' : ' is-hidden'; ?>">
		<p class="description">
			Официалният списък се зарежда от Speedy и съдържа всички офиси и АПС в България. След отваряне на списъка може да започнете да пишете вътре в него.
		</p>

		<p class="form-row form-row-wide">
			<label for="sameday_speedy_city">
				Населено място <span class="required">*</span>
			</label>
			<select
				name="sameday_speedy_city"
				id="sameday_speedy_city"
				class="select"
				data-selected="<?php echo esc_attr( $selection['speedy_city_id'] ); ?>"
			>
				<option value="">Зареждане...</option>
			</select>
		</p>

		<p class="form-row form-row-wide">
			<label for="sameday_speedy_location" id="sameday-speedy-location-label">
				<span class="sameday-speedy-label-text">
					<?php echo 'speedy_aps' === $selection['service'] ? esc_html__( 'Speedy АПС', 'sameday-woocommerce-bg' ) : esc_html__( 'Speedy офис', 'sameday-woocommerce-bg' ); ?>
				</span>
				<span class="required">*</span>
			</label>
			<select
				name="sameday_speedy_location"
				id="sameday_speedy_location"
				class="select"
				data-selected="<?php echo esc_attr( $selection['speedy_location'] ); ?>"
			>
				<?php if ( $selected_speedy_location ) : ?>
					<option value="<?php echo esc_attr( $selected_speedy_location['id'] ); ?>" selected="selected">
						<?php echo esc_html( $selected_speedy_location['label'] ); ?>
					</option>
				<?php else : ?>
					<option value="">
						<?php echo 'speedy_aps' === $selection['service'] ? esc_html__( 'Изберете Speedy АПС', 'sameday-woocommerce-bg' ) : esc_html__( 'Изберете Speedy офис', 'sameday-woocommerce-bg' ); ?>
					</option>
				<?php endif; ?>
			</select>
			<span id="sameday_speedy_help" class="description"></span>
			<span id="sameday_speedy_selected_meta" class="description">
				<?php if ( $selected_speedy_location ) : ?>
					<?php echo esc_html( $selected_speedy_location['details'] ); ?>
				<?php endif; ?>
			</span>
		</p>
	</div>

	<div class="speedy-door-fields<?php echo 'speedy_door' === $selection['service'] ? '' : ' is-hidden'; ?>">
		<p class="form-row form-row-wide">
			<label for="speedy_door_city">
				Населено място <span class="required">*</span>
			</label>
			<input
				type="text"
				name="speedy_door_city"
				id="speedy_door_city"
				class="input-text"
				value="<?php echo esc_attr( $selection['speedy_door_city'] ); ?>"
				placeholder="Напишете вашият Град или Село"
			/>
		</p>

		<p class="form-row form-row-wide">
			<label for="speedy_door_address">
				Адрес <span class="required">*</span>
			</label>
			<input
				type="text"
				name="speedy_door_address"
				id="speedy_door_address"
				class="input-text"
				value="<?php echo esc_attr( $selection['speedy_door_address'] ); ?>"
				placeholder="Вашият адрес за доставка"
			/>
		</p>
	</div>

	<div class="a1post-fields<?php echo 'a1post_international' === $selection['service'] ? '' : ' is-hidden'; ?>">
		<p class="description">
			<?php
			if ( '' !== $a1post_note ) {
				echo esc_html( $a1post_note );
			} else {
				esc_html_e( 'Доставката се извършва от A1POST до адреса, попълнен в поръчката.', 'sameday-woocommerce-bg' );
			}
			?>
		</p>

		<p class="form-row form-row-wide">
			<label for="a1post_name">Получател <span class="required">*</span></label>
			<input type="text" name="a1post_name" id="a1post_name" class="input-text" value="<?php echo esc_attr( $selection['a1post_name'] ); ?>" placeholder="Име и фамилия" maxlength="40" />
		</p>

		<p class="form-row form-row-first">
			<label for="a1post_phone">Телефон <span class="required">*</span></label>
			<input type="text" name="a1post_phone" id="a1post_phone" class="input-text" value="<?php echo esc_attr( $selection['a1post_phone'] ); ?>" placeholder="+39..." />
		</p>

		<p class="form-row form-row-last">
			<label for="a1post_email">Имейл <span class="required">*</span></label>
			<input type="email" name="a1post_email" id="a1post_email" class="input-text" value="<?php echo esc_attr( $selection['a1post_email'] ); ?>" placeholder="email@example.com" />
		</p>

		<p class="form-row form-row-wide">
			<label for="a1post_address_1">Адрес <span class="required">*</span></label>
			<input type="text" name="a1post_address_1" id="a1post_address_1" class="input-text" value="<?php echo esc_attr( $selection['a1post_address_1'] ); ?>" placeholder="Улица, номер, вход, апартамент" />
		</p>

		<p class="form-row form-row-wide">
			<label for="a1post_address_2">Адрес 2</label>
			<input type="text" name="a1post_address_2" id="a1post_address_2" class="input-text" value="<?php echo esc_attr( $selection['a1post_address_2'] ); ?>" placeholder="Допълнителен адрес, квартал, фирма" />
		</p>

		<p class="form-row form-row-first">
			<label for="a1post_city">Град <span class="required">*</span></label>
			<input type="text" name="a1post_city" id="a1post_city" class="input-text" value="<?php echo esc_attr( $selection['a1post_city'] ); ?>" placeholder="Roma" />
		</p>

		<p class="form-row form-row-last">
			<label for="a1post_postcode">Пощенски код <span class="required">*</span></label>
			<input type="text" name="a1post_postcode" id="a1post_postcode" class="input-text" value="<?php echo esc_attr( $selection['a1post_postcode'] ); ?>" placeholder="00100" />
		</p>

		<p class="form-row form-row-wide">
			<label for="a1post_state">Област / щат</label>
			<input type="text" name="a1post_state" id="a1post_state" class="input-text" value="<?php echo esc_attr( $selection['a1post_state'] ); ?>" placeholder="Lazio" />
		</p>

		<p class="form-row form-row-wide">
			<label for="a1post_notes">Уточнения за доставката</label>
			<textarea name="a1post_notes" id="a1post_notes" class="input-text" rows="2" placeholder="Звънец, работно време, инструкции към куриера"><?php echo esc_textarea( $selection['a1post_notes'] ); ?></textarea>
		</p>
	</div>

	<div id="sameday-delivery-summary" class="sameday-delivery-summary is-hidden<?php echo $show_price ? ' has-price' : ''; ?><?php echo ! empty( $show_free_shipping ) ? ' is-free-shipping' : ''; ?>">
		<?php if ( ! empty( $show_free_shipping ) ) : ?>
			<strong>Вие получавате безплатна доставка!</strong>
			<?php if ( ! empty( $free_shipping_by_card ) ) : ?>
				<span class="sameday-free-shipping-reason">Доставката е безплатна, защото плащате с карта.</span>
			<?php endif; ?>
		<?php elseif ( $show_price ) : ?>
			<strong>Вашата цена за доставка е <?php echo wp_kses_post( wc_price( $delivery_price ) ); ?></strong>
		<?php elseif ( empty( $selection['service'] ) ) : ?>
			Изберете тип доставка, за да изчислим цената.
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $card_rule_active ) ) : ?>
		<p class="sameday-card-free-shipping-hint">
			<?php
			echo wp_kses_post(
				sprintf(
					'all' === $card_payment_scope
						? 'Безплатна доставка за поръчки над %s.'
						: 'Безплатна доставка при плащане с карта за поръчки над %s.',
					wc_price( $card_threshold )
				)
			);
			?>
		</p>
	<?php endif; ?>

	<input type="hidden" id="sameday_cart_weight" value="<?php echo esc_attr( $cart_weight ); ?>" />
	<input type="hidden" id="sameday_cart_subtotal" value="<?php echo esc_attr( $cart_subtotal ); ?>" />
	<input type="hidden" id="sameday_delivery_country" value="<?php echo esc_attr( $country ); ?>" />
</div>
