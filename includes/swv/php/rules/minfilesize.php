<?php

class WPCF7_SWV_MinFileSizeRule extends WPCF7_SWV_Rule {

	const rule_name = 'minfilesize';

	public function matches( $context ) {
		if ( false === parent::matches( $context ) ) {
			return false;
		}

		if ( empty( $context['file'] ) ) {
			return false;
		}

		return true;
	}

	public function validate( $context ) {
		$field = $this->get_property( 'field' );
		$input = isset( $_FILES[$field]['size'] ) ? $_FILES[$field]['size'] : '';
		$input = wpcf7_array_flatten( $input );
		$input = wpcf7_exclude_blank( $input );

		if ( empty( $input ) ) {
			return true;
		}

		$threshold = $this->get_property( 'threshold' );

		if ( array_sum( $input ) < $threshold ) {
			return $this->create_error();
		}

		return true;
	}

}
