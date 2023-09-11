<?php

trait WPCF7_ConfigValidator_Mail {

	/**
	 * Replaces all mail-tags in the given content.
	 */
	public function replace_mail_tags( $content, $args = '' ) {
		$args = wp_parse_args( $args, array(
			'html' => false,
			'callback' =>
				array( $this, 'replace_mail_tags_with_minimum_input_callback' ),
		) );

		$content = new WPCF7_MailTaggedText( $content, $args );

		return $content->replace_tags();
	}


	/**
	 * Callback function for WPCF7_MailTaggedText. Replaces mail-tags with
	 * the most conservative inputs.
	 */
	public function replace_mail_tags_with_minimum_input_callback( $matches ) {
		// allow [[foo]] syntax for escaping a tag
		if ( $matches[1] === '[' and $matches[4] === ']' ) {
			return substr( $matches[0], 1, -1 );
		}

		$tag = $matches[0];
		$tagname = $matches[2];
		$values = $matches[3];

		$mail_tag = new WPCF7_MailTag( $tag, $tagname, $values );
		$field_name = $mail_tag->field_name();

		$example_email = 'example@example.com';
		$example_text = 'example';
		$example_blank = '';

		$form_tags = $this->contact_form->scan_form_tags(
			array( 'name' => $field_name )
		);

		if ( $form_tags ) {
			$form_tag = new WPCF7_FormTag( $form_tags[0] );

			$is_required = $form_tag->is_required() || 'radio' === $form_tag->type;

			if ( ! $is_required ) {
				return $example_blank;
			}

			if ( wpcf7_form_tag_supports( $form_tag->type, 'selectable-values' ) ) {
				if ( $form_tag->pipes instanceof WPCF7_Pipes ) {
					if ( $mail_tag->get_option( 'do_not_heat' ) ) {
						$before_pipes = $form_tag->pipes->collect_befores();
						$last_item = array_pop( $before_pipes );
					} else {
						$after_pipes = $form_tag->pipes->collect_afters();
						$last_item = array_pop( $after_pipes );
					}
				} else {
					$last_item = array_pop( $form_tag->values );
				}

				if ( $last_item and wpcf7_is_mailbox_list( $last_item ) ) {
					return $example_email;
				} else {
					return $example_text;
				}
			}

			if ( 'email' === $form_tag->basetype ) {
				return $example_email;
			} else {
				return $example_text;
			}

		} else { // maybe special mail tag
			// for back-compat
			$field_name = preg_replace( '/^wpcf7\./', '_', $field_name );

			if ( '_site_admin_email' === $field_name ) {
				return get_bloginfo( 'admin_email', 'raw' );

			} elseif ( '_user_agent' === $field_name ) {
				return $example_text;

			} elseif ( '_user_email' === $field_name ) {
				return $this->contact_form->is_true( 'subscribers_only' )
					? $example_email
					: $example_blank;

			} elseif ( str_starts_with( $field_name, '_user_' ) ) {
				return $this->contact_form->is_true( 'subscribers_only' )
					? $example_text
					: $example_blank;

			} elseif ( str_starts_with( $field_name, '_' ) ) {
				return str_ends_with( $field_name, '_email' )
					? $example_email
					: $example_text;

			}
		}

		return $tag;
	}


	/**
	 * Runs error detection for the mail sections.
	 */
	public function validate_mail( $template = 'mail' ) {
		if (
			$this->contact_form->is_true( 'demo_mode' ) or
			$this->contact_form->is_true( 'skip_mail' )
		) {
			return;
		}

		$components = (array) $this->contact_form->prop( $template );

		if ( ! $components ) {
			return;
		}

		if ( 'mail' !== $template and empty( $components['active'] ) ) {
			return;
		}

		$components = wp_parse_args( $components, array(
			'subject' => '',
			'sender' => '',
			'recipient' => '',
			'additional_headers' => '',
			'body' => '',
			'attachments' => '',
		) );

		$this->detect_maybe_empty(
			sprintf( '%s.subject', $template ),
			$components['subject']
		);

		$invalid_mailbox = $this->detect_invalid_mailbox_syntax(
			sprintf( '%s.sender', $template ),
			$components['sender']
		);

		if ( ! $invalid_mailbox ) {
			$sender = $this->replace_mail_tags( $components['sender'] );
			$sender = wpcf7_strip_newline( $sender );

			if ( ! wpcf7_is_email_in_site_domain( $sender ) ) {
				$this->add_error( sprintf( '%s.sender', $template ),
					'email_not_in_site_domain',
					array(
						'message' => __( "Sender email address does not belong to the site domain.", 'contact-form-7' ),
					)
				);
			}
		}

		$invalid_mailbox = $this->detect_invalid_mailbox_syntax(
			sprintf( '%s.recipient', $template ),
			$components['recipient']
		);

		if ( ! $invalid_mailbox ) {
			$this->detect_unsafe_email_without_protection(
				sprintf( '%s.recipient', $template ),
				$components['recipient']
			);
		}

		$invalid_mail_header_exists = false;

		$additional_headers = explode( "\n", $components['additional_headers'] );

		foreach ( $additional_headers as $header ) {
			$header = trim( $header );

			if ( '' === $header ) {
				continue;
			}

			if ( ! preg_match( '/^([0-9A-Za-z-]+):(.*)$/', $header, $matches ) ) {
				$invalid_mail_header_exists = true;
			} else {
				$header_name = $matches[1];
				$header_value = trim( $matches[2] );

				if (
					in_array(
						strtolower( $header_name ),
						array( 'reply-to', 'cc', 'bcc' )
					) and
					'' !== $header_value
				) {
					$invalid_mailbox = $this->detect_invalid_mailbox_syntax(
						sprintf( '%s.additional_headers', $template ),
						$header_value,
						array(
							'message' => __( "Invalid mailbox syntax is used in the %name% field.", 'contact-form-7' ),
							'params' => array( 'name' => $header_name )
						)
					);

					if (
						! $invalid_mailbox and
						in_array(
							strtolower( $header_name ),
							array( 'cc', 'bcc' )
						)
					) {
						$this->detect_unsafe_email_without_protection(
 							sprintf( '%s.additional_headers', $template ),
 							$header_value
 						);
					}
				}
			}
		}

		if ( $invalid_mail_header_exists ) {
			$this->add_error( sprintf( '%s.additional_headers', $template ),
				'invalid_mail_header',
				array(
					'message' => __( "There are invalid mail header fields.", 'contact-form-7' ),
				)
			);
		}

		$this->detect_maybe_empty(
			sprintf( '%s.body', $template ),
			$components['body']
		);

		if ( '' !== $components['attachments'] ) {
			$attachables = array();

			$tags = $this->contact_form->scan_form_tags(
				array( 'type' => array( 'file', 'file*' ) )
			);

			foreach ( $tags as $tag ) {
				$name = $tag->name;

				if ( ! str_contains( $components['attachments'], "[{$name}]" ) ) {
					continue;
				}

				$limit = (int) $tag->get_limit_option();

				if ( empty( $attachables[$name] ) or $attachables[$name] < $limit ) {
					$attachables[$name] = $limit;
				}
			}

			$total_size = array_sum( $attachables );

			$has_file_not_found = false;
			$has_file_not_in_content_dir = false;

			foreach ( explode( "\n", $components['attachments'] ) as $line ) {
				$line = trim( $line );

				if ( '' === $line or str_starts_with( $line, '[' ) ) {
					continue;
				}

				$has_file_not_found = $this->detect_file_not_found(
					sprintf( '%s.attachments', $template ), $line
				);

				if ( ! $has_file_not_found and ! $has_file_not_in_content_dir ) {
					$has_file_not_in_content_dir = $this->detect_file_not_in_content_dir(
						sprintf( '%s.attachments', $template ), $line
					);
				}

				if ( ! $has_file_not_found ) {
					$path = path_join( WP_CONTENT_DIR, $line );
					$total_size += (int) @filesize( $path );
				}
			}

			$max = 25 * MB_IN_BYTES; // 25 MB

			if ( $max < $total_size ) {
				$this->add_error( sprintf( '%s.attachments', $template ),
					'attachments_overweight',
					array(
						'message' => __( "The total size of attachment files is too large.", 'contact-form-7' ),
					)
				);
			}
		}
	}


	/**
	 * Detects errors of invalid mailbox syntax.
	 *
	 * @link https://contactform7.com/configuration-errors/invalid-mailbox-syntax/
	 */
	public function detect_invalid_mailbox_syntax( $section, $content, $args = '' ) {
		$args = wp_parse_args( $args, array(
			'message' => __( "Invalid mailbox syntax is used.", 'contact-form-7' ),
			'params' => array(),
		) );

		$content = $this->replace_mail_tags( $content );
		$content = wpcf7_strip_newline( $content );

		if ( ! wpcf7_is_mailbox_list( $content ) ) {
			return $this->add_error( $section,
				'invalid_mailbox_syntax',
				$args
			);
		}

		return false;
	}


	/**
	 * Detects errors of empty message fields.
	 *
	 * @link https://contactform7.com/configuration-errors/maybe-empty/
	 */
	public function detect_maybe_empty( $section, $content ) {
		$content = $this->replace_mail_tags( $content );
		$content = wpcf7_strip_newline( $content );

		if ( '' === $content ) {
			return $this->add_error( $section,
				'maybe_empty',
				array(
					'message' => __( "There is a possible empty field.", 'contact-form-7' ),
				)
			);
		}

		return false;
	}


	/**
	 * Detects errors of nonexistent attachment files.
	 *
	 * @link https://contactform7.com/configuration-errors/file-not-found/
	 */
	public function detect_file_not_found( $section, $content ) {
		$path = path_join( WP_CONTENT_DIR, $content );

		if ( ! is_readable( $path ) or ! is_file( $path ) ) {
			return $this->add_error( $section,
				'file_not_found',
				array(
					'message' => __( "Attachment file does not exist at %path%.", 'contact-form-7' ),
					'params' => array( 'path' => $content ),
				)
			);
		}

		return false;
	}


	/**
	 * Detects errors of attachment files out of the content directory.
	 *
	 * @link https://contactform7.com/configuration-errors/file-not-in-content-dir/
	 */
	public function detect_file_not_in_content_dir( $section, $content ) {
		$path = path_join( WP_CONTENT_DIR, $content );

		if ( ! wpcf7_is_file_path_in_content_dir( $path ) ) {
			return $this->add_error( $section,
				'file_not_in_content_dir',
				array(
					'message' => __( "It is not allowed to use files outside the wp-content directory.", 'contact-form-7' ),
				)
			);
		}

		return false;
	}


	/**
	 * Detects errors of that unsafe email config is used without
	 * sufficient protection.
	 *
	 * @link https://contactform7.com/configuration-errors/unsafe-email-without-protection/
	 */
	public function detect_unsafe_email_without_protection( $section, $content ) {
		static $is_recaptcha_active = null;

		if ( null === $is_recaptcha_active ) {
			$is_recaptcha_active = call_user_func( function () {
				$service = WPCF7_RECAPTCHA::get_instance();
				return $service->is_active();
			} );
		}

		if ( $is_recaptcha_active ) {
			return false;
		}

		$example_email = 'example@example.com';

		// Replace mail-tags connected to an email type form-tag first.
		$content = $this->replace_mail_tags( $content, array(
			'callback' => function ( $matches ) use ( $example_email ) {
				// allow [[foo]] syntax for escaping a tag
				if ( $matches[1] === '[' and $matches[4] === ']' ) {
					return substr( $matches[0], 1, -1 );
				}

				$tag = $matches[0];
				$tagname = $matches[2];
				$values = $matches[3];

				$mail_tag = new WPCF7_MailTag( $tag, $tagname, $values );
				$field_name = $mail_tag->field_name();

				$form_tags = $this->contact_form->scan_form_tags(
					array( 'name' => $field_name )
				);

				if ( $form_tags ) {
					$form_tag = new WPCF7_FormTag( $form_tags[0] );

					if ( 'email' === $form_tag->basetype ) {
						return $example_email;
					}
				}

				return $tag;
			},
		) );

		// Replace remaining mail-tags.
		$content = $this->replace_mail_tags( $content );

		$content = wpcf7_strip_newline( $content );

		if ( str_contains( $content, $example_email ) ) {
			return $this->add_error( $section,
				'unsafe_email_without_protection',
				array(
					'message' => __( "Unsafe email config is used without sufficient protection.", 'contact-form-7' ),
				)
			);
		}

		return false;
	}

}