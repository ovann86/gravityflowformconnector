<?php

if ( class_exists( 'Gravity_Flow_Step' ) ) {

	class Gravity_Flow_Step_Form_Connector extends Gravity_Flow_Step {
		public $_step_type = 'form_connector';

		public function get_label() {
			return esc_html__( 'Form Connector', 'gravityflowformconnector' );
		}

		public function get_settings(){

			$forms = GFFormsModel::get_forms();
			$form_choices[] = array( 'label' => esc_html__( 'Select a Form', 'gravityflowformconnector' ), 'value' => '' );
			foreach ( $forms  as $form ) {
				$form_choices[] = array( 'label' => $form->title, 'value' => $form->id );
			}

			return array(
				'title'  => 'Form Connector',
				'fields' => array(
					array(
						'name' => 'target_form_id',
						'label' => esc_html__( 'Target form', 'gravityflowformconnector' ),
						'type' => 'select',
						'onchange' => 'jQuery(this).parents("form").submit();',
						'choices' => $form_choices,
					),
					array(
						'name' => 'action',
						'label' => esc_html__( 'Action', 'gravityflowformconnector' ),
						'type' => 'radio',
						'horizontal' => true,
						'onchange' => 'jQuery(this).parents("form").submit();',
						'choices' => $this->action_choices(),
						'dependency' => array(
							'field'  => 'target_form_id',
							'values' => array( '_notempty_' ),
						),
					),
					array(
						'name' => 'update_entry_id',
						'label' => esc_html__( 'Entry ID Field', 'gravityflowformconnector' ),
						'type' => 'field_select',
						'dependency' => array(
							'field'  => 'action',
							'values' => array( 'update', 'approval', 'user_input' ),
						),
					),
					array(
						'name' => 'approval_status_field',
						'label' => esc_html__( 'Approval Status Field', 'gravityflowformconnector' ),
						'type' => 'field_select',
						'dependency' => array(
							'field'  => 'action',
							'values' => array( 'approval' ),
						),
					),
					array(
						'name' => 'mappings',
						'label' => esc_html__( 'Field Mapping', 'gravityflowformconnector' ),
						'type'      => 'dynamic_field_map',
						'disable_custom' => true,
						'field_map' => $this->field_mappings(),
						'tooltip'   => '<h6>' . esc_html__( 'Mapping', 'gravityflowformconnector' ) . '</h6>' . esc_html__( 'Map the fields of this form to the selected form. Values from this form will be saved in the entry in the selected form' , 'gravityflowformconnector' ),
						'dependency' => array(
							'field'  => 'action',
							'values' => array( 'create', 'update', 'user_input' ),
						),
					),
				),
			);
		}

		public function action_choices(){
			$choices = array(
				array( 'label' => esc_html__( 'Create an Entry', 'gravityflow' ), 'value' => 'create' ),
				array( 'label' => esc_html__( 'Update an Entry', 'gravityflow' ), 'value' => 'update' ),
			);

			$target_form_id = gravity_flow()->get_setting( 'target_form_id' );

			if ( ! empty( $target_form_id ) ) {
				$api = new Gravity_Flow_API( $target_form_id );
				$steps = $api->get_steps();
				$has_approval_step = false;
				$has_user_input_step = false;
				foreach ( $steps as $step ) {
					if ( $step->get_type() == 'approval' ) {
						$has_approval_step = true;
					} elseif ( $step->get_type() == 'user_input' ) {
						$has_user_input_step = true;
					}
				}
				if ( $has_approval_step ) {
					$choices[] = array( 'label' => esc_html__( 'Approval', 'gravityflow' ), 'value' => 'approval' );
				}
				if ( $has_user_input_step ) {
					$choices[] = array( 'label' => esc_html__( 'User Input', 'gravityflow' ), 'value' => 'user_input' );
				}
			}
			return $choices;
		}

		/**
		 * Prepare field map.
		 *
		 * @return array
		 */
		public function field_mappings() {

			$fields = array(
				array(
					'label' => esc_html__( 'Select a Target Field', 'gravityformsformconnector' ),
					'value' => '',
				),
			);

			$target_form_id = gravity_flow()->get_setting( 'target_form_id' );

			if ( empty( $target_form_id ) ) {
				return false;
			}

			$remote_form = GFAPI::get_form( $target_form_id );
			$remote_fields = $remote_form['fields'];

			foreach ( $remote_fields as $field ) {

				$fields[] = array(
					'label' => $field->label,
					'value' => $field->id,
				);
			}

			$target_form_entry_metas = GFFormsModel::get_entry_meta( $target_form_id );

			foreach ( $target_form_entry_metas as $key => $target_form_entry_meta ) {

				$fields[] = array(
					'label' => $target_form_entry_meta['label'],
					'value' => $key,
				);

			}

			return $fields;

		}

		function process(){

			$entry = $this->get_entry();

			$new_entry = array(
				'form_id' => $this->target_form_id,
			);

			$api = new Gravity_Flow_API( $this->target_form_id );
			$steps = $api->get_steps();

			if ( is_array( $this->mappings ) ) {
				foreach ( $this->mappings as $mapping ) {

					if ( rgblank( $mapping['key'] ) ) {
						continue;
					}

					$target_field_id = trim( $mapping['key'] );
					$external_field_id = $mapping['value'];
					$new_entry[ $target_field_id ] = $entry[ $external_field_id ];

				}
			}

			switch ( $this->action ) {
				case 'create' :
					$target_entry_id = GFAPI::add_entry( $new_entry );
					$target_entry = GFAPI::get_entry( $target_entry_id );

					// Simulate submission to add our entry meta
					$form = GFAPI::get_form( $this->target_form_id );

					gravity_flow()->maybe_process_feed( $target_entry, $form );
					if ( $steps ) {
						$api->process_workflow( $target_entry_id );
					}

					return true;
				break;
				case 'update' :
				case 'user_input' :
					$target_entry_id = rgar( $entry, $this->update_entry_id );
					$target_entry = GFAPI::get_entry( $target_entry_id );

					foreach ( $new_entry as $key => $value ) {
						$target_entry[ $key ] = $value ;
					}
					GFAPI::update_entry( $target_entry );
				break;
				case 'approval' :
					$target_entry_id = rgar( $entry, $this->update_entry_id );
					$target_entry = GFAPI::get_entry( $target_entry_id );
					break;
			}

			if ( empty ( $target_entry_id ) || empty ( $target_entry ) ) {
				return true;
			}

			if ( in_array( $this->action, array( 'approval', 'user_input' ) ) && $steps ) {

				if ( $target_entry['workflow_final_status'] != 'pending' ) {
					$current_step = $api->get_current_step( $target_entry );

					if ( $current_step ) {
						$current_user_status = $current_step->get_user_status();

						$status = ( $this->action == 'approval' ) ? strtolower( rgar( $entry, $this->approval_status_field ) ) : 'complete';

						$current_role_status = false;
						$role = false;
						foreach ( gravity_flow()->get_user_roles() as $role ) {
							$current_role_status = $current_step->get_role_status( $role );
							if ( $current_role_status == 'pending' ) {
								break;
							}
						}
						if ( $current_user_status == 'pending' ) {
							if ( $token = gravity_flow()->decode_access_token() ) {
								$assignee_key = sanitize_text_field( $token['sub'] );

							} else {
								$user = wp_get_current_user();
								$assignee_key = 'user_id|' . $user->ID;
							}
							$assignee = new Gravity_Flow_Assignee( $assignee_key, $current_step );
							$assignee->update_status( $status );
						}

						if ( $current_role_status == 'pending' ) {
							$current_step->update_role_status( $role, $status );
						}

						$api->process_workflow( $target_entry_id );
					}

				}
			}

			return true;
		}
	}
}


