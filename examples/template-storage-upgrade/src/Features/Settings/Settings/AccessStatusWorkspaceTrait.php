<?php
/** Template-scoped checkpoints and frozen publication editing. */
namespace MA_CO_AccessControlPro\Features\Settings\Settings;

use MA_CO_AccessControlPro\Database\AccessStatusStorage;
use MA_CO_AccessControlPro\Database\AccessStatusSchema;

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait AccessStatusWorkspaceTrait {
	public function workspace_command( $operation, $id, $payload, $expected_hash, array $args = [] ) {
		if ( ! \MA_CO_AccessControlPro\Utilities\PluginAccess::can( 'wc_duration' ) ) { return new \WP_Error( 'workspace_forbidden', __( 'دسترسی کافی نیست.', 'ma-co-access-control-pro' ) ); }
		if ( ! in_array( $id, \MA_CO_AccessControlPro\Features\WooCommerce\AccessStatusTemplateSanitizer::get_factory_ids(), true ) && ! preg_match( '/^custom-[a-z0-9][a-z0-9-]{7,63}$/D', $id ) ) { return new \WP_Error( 'missing_template', __( 'شناسه قالب معتبر نیست.', 'ma-co-access-control-pro' ) ); }
		return $this->run_locked_mutation( function () use ( $operation, $id, $payload, $expected_hash, $args ) {
			$id = sanitize_key( $id );
			$storage = new AccessStatusStorage();
			$set = 'steps-' . substr( hash( 'sha256', get_current_user_id() . '|' . $id ), 0, 56 );
			$steps = $storage->read_history_set( $set );
			if ( is_wp_error( $steps ) ) { return $steps; }
			$revision = AccessStatusStorage::hash( $steps );
			if ( 'list' === $operation ) { return $this->workspace_step_list( $steps, $revision ); }
			$conflict = $this->check_expected_hash( $expected_hash );
			if ( is_wp_error( $conflict ) ) { return $conflict; }
			if ( in_array( $operation, [ 'save', 'delete', 'load' ], true ) && ! hash_equals( $revision, (string) ( $args['steps_revision'] ?? '' ) ) ) {
				return new \WP_Error( 'steps_conflict', __( 'فهرست مراحل در نشست دیگری تغییر کرده؛ فهرست را تازه کنید.', 'ma-co-access-control-pro' ) );
			}
			if ( 'delete' === $operation ) {
				global $wpdb;
				$table = AccessStatusSchema::tables()['access_status_template_history'];
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE history_set = %s AND snapshot_id = %s", $set, sanitize_text_field( $args['step_id'] ?? '' ) ) );
				if ( false === $deleted ) { return new \WP_Error( 'step_delete', __( 'حذف مرحله انجام نشد.', 'ma-co-access-control-pro' ) ); }
				$steps = $storage->read_history_set( $set );
				return is_wp_error( $steps ) ? $steps : $this->workspace_step_list( $steps, AccessStatusStorage::hash( $steps ) );
			}
			$working = $this->repository->sanitize_payload( $payload );
			if ( is_wp_error( $working ) ) { return $working; }
			if ( ! isset( $working['templates'][ $id ] ) ) { return new \WP_Error( 'missing_template', __( 'قالب در پیش‌نویس موجود نیست.', 'ma-co-access-control-pro' ) ); }
			if ( 'save' === $operation ) {
				$name = sanitize_text_field( $args['name'] ?? '' );
				if ( '' === trim( $name ) || preg_match_all( '/./us', $name ) > 80 ) { return new \WP_Error( 'step_name', __( 'نام مرحله را وارد کنید؛ حداکثر ۸۰ نویسه.', 'ma-co-access-control-pro' ) ); }
				$record = $this->workspace_capture( $working, $id );
				if ( is_wp_error( $record ) ) { return $record; }
				$step = [ 'id' => wp_generate_uuid4(), 'created_at' => time(), 'user_id' => get_current_user_id(), 'reason' => 'named_step', 'name' => $name, 'state' => $record, 'context' => $this->workspace_context( $args['context'] ?? [] ) ];
				$collection = array_merge( [ $step ], $steps );
				if ( count( $collection ) > 10 || strlen( (string) wp_json_encode( $collection ) ) > 2097152 ) {
					return new \WP_Error( 'steps_limit', __( 'سقف ۱۰ مرحله یا ۲ مگابایت پر شده است؛ یکی از مراحل قبلی را حذف کنید.', 'ma-co-access-control-pro' ) );
				}
				$result = $storage->insert_snapshot( $step, $set );
				return is_wp_error( $result ) ? $result : $this->workspace_step_list( $collection, AccessStatusStorage::hash( $collection ) );
			}
			if ( ! in_array( $operation, [ 'published', 'start', 'load' ], true ) ) { return new \WP_Error( 'workspace_operation', __( 'فرمان معتبر نیست.', 'ma-co-access-control-pro' ) ); }
			$context = [];
			if ( 'load' === $operation ) {
				$record = null;
				foreach ( $steps as $step ) { if ( $step['id'] === ( $args['step_id'] ?? '' ) ) { $record = $step['state']; $context = $step['context'] ?? []; break; } }
				if ( ! $record ) { return new \WP_Error( 'step_missing', __( 'مرحله پیدا نشد.', 'ma-co-access-control-pro' ) ); }
			} else {
				$published = $this->repository->get_published_state();
				if ( is_wp_error( $published ) ) { return $published; }
				$publication = ( new AccessStatusPublicationService( $this->repository ) )->status();
				if ( is_wp_error( $publication ) ) { return $publication; }
				if ( ! hash_equals( (string) $publication['revision'], (string) ( $args['publication_revision'] ?? '' ) ) ) { return new \WP_Error( 'publication_changed', __( 'نسخه سایت تغییر کرده؛ وضعیت انتشار را تازه کنید.', 'ma-co-access-control-pro' ) ); }
				if ( ! isset( $published['templates'][ $id ] ) ) { return new \WP_Error( 'not_published', __( 'این قالب هنوز منتشر نشده است.', 'ma-co-access-control-pro' ) ); }
				$record = $this->workspace_capture( $published, $id );
				if ( is_wp_error( $record ) ) { return $record; }
			}
			$before = $working;
			$working['templates'][ $id ] = $record['template'];
			$working['template_visibility'][ $id ] = $record['visibility'];
			$clean = $this->repository->sanitize_payload( $working );
			if ( is_wp_error( $clean ) ) { return $clean; }
			if ( 'published' !== $operation ) {
				$saved = $this->push_snapshot( $before, 'before_workspace_restore' );
				if ( is_wp_error( $saved ) ) { return $saved; }
			}
			// Unsaved projections never write either library manifest.
			return [ 'template' => $clean['templates'][ $id ], 'visibility' => $record['visibility'], 'context' => $context, 'state_hash' => $this->get_state_hash(), 'history' => $this->get_history() ];
		} );
	}

	private function workspace_context( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];
		$choices = [ 'scenario' => array_merge( [ 'all' ], \MA_CO_AccessControlPro\Features\WooCommerce\AccessStatusTemplateSanitizer::get_state_keys() ), 'surface' => [ 'blocks', 'code' ], 'codeTab' => [ 'product_html', 'message_html', 'css' ], 'sidebar' => [ 'settings', 'appearance', 'structure' ], 'device' => [ 'desktop', 'tablet', 'mobile' ], 'previewDevice' => [ 'desktop', 'tablet', 'mobile' ], 'previewOrientation' => [ 'template', 'horizontal', 'vertical' ], 'previewLayout' => [ 'horizontal', 'vertical' ], 'previewZoom' => [ 'fit', '0.5', '0.75', '1', '1.25' ] ];
		$result = [];
		foreach ( $choices as $key => $allowed ) { if ( in_array( $raw[ $key ] ?? '', $allowed, true ) ) { $result[ $key ] = $raw[ $key ]; } }
		if ( isset( $raw['previewCustomWidth'] ) && is_numeric( $raw['previewCustomWidth'] ) ) { $width = (int) $raw['previewCustomWidth']; if ( 0 === $width || ( $width >= 320 && $width <= 1440 ) ) { $result['previewCustomWidth'] = $width; } }
		return $result;
	}

	private function workspace_step_list( array $steps, $revision ) {
		return [ 'steps_revision' => $revision, 'steps' => array_map( static function ( $step ) { return [ 'id' => $step['id'], 'name' => $step['name'], 'created_at' => $step['created_at'] ]; }, $steps ), 'bytes' => strlen( (string) wp_json_encode( $steps ) ), 'limit' => 10, 'byte_limit' => 2097152 ];
	}

	private function workspace_capture( array $state, $id ) {
		$template = $state['templates'][ $id ];
		if ( ! isset( $template['foundation_snapshot'] ) ) {
			$base = [];
			foreach ( [ 'mode', 'blocks', 'code', 'styles', 'default_orientation', 'info_columns', 'date_display' ] as $key ) { $base[ $key ] = $template[ $key ] ?? []; }
			$template['foundation_snapshot'] = [ 'base' => $base, 'shared_base' => $state['shared_base'] ];
		}
		return [ 'template' => $template, 'visibility' => $state['template_visibility'][ $id ] ?? [] ];
	}
}
