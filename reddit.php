...
...
    /**
		 * Get Reddit User agent.
		 *
		 * @since 1.2.4
		 */
		public function wptwbot_get_user_agent() {
			$wptwbot_options = get_option( 'wptwbot_options' );
			return 'web:AiSP:v' . WPTWBOT_VER . ' (by /u/' . $wptwbot_options['reddit_key'] . ')';
		}

		/**
		 * Share on Reddit.
		 *
		 * $xx = $this->post( ['text'=>'This is Hello World', 'user_id'=>1, 'media'=> array( 'woodsign.gif' ) ], 'text-link'=>'https://', 'subreddit'=>'', subreddit_flair'=>'', 'title'=>'' ); print_r( $xx ); exit();
		 */
		public function post( $arr = array() ) {
			$uploads = wptwbot_set_upload();

			if ( defined( 'WPTWBOT_DISABLE_POSTS' ) && WPTWBOT_DISABLE_POSTS !== false ) {
				return new WP_Error( 'wptwbot_post_error', esc_attr( 'Debug Stop: ' . print_r( $arr, true ) ) );
			}

			if ( ! empty( $arr['user_id'] ) ) {
				$user_id = $arr['user_id'];
			} elseif ( is_user_logged_in() ) {
				$user_id = get_myteam_current_user_id();
			} else {
				return new WP_Error( 'post', esc_attr( 'No User found' ) );
			}

			$wptwbot_options = get_option( 'wptwbot_options' );
			if ( empty( $wptwbot_options['reddit_key'] ) ) {
				return new WP_Error( 'reddit_error', esc_attr( __( 'Reddit Consumer key not found', 'ai-twitterbot' ) ) );
			}

			$socials = get_user_socials( $user_id );
			if ( empty( $socials['reddit'] ) ) {
				return new WP_Error( 'reddit_error', esc_attr__( 'reddit not authorized', 'ai-twitterbot' ) . '>>user_id: ' . $user_id . ';;' . print_r( $socials, true ) );
			}
			$wptwbot            = $socials['reddit'];
			$wptwbot['user_id'] = $user_id;

			$wptwbot = $this->refresh_token( $wptwbot );
			if ( is_wp_error( $wptwbot ) ) {
				return $wptwbot;
			}

			$header                  = array();
			$header['Authorization'] = 'bearer ' . wptwbot_decrypt_str( $wptwbot['atoken'] );
			$header['Content-Type']  = 'application/json';
			$header['User-Agent']    = $this->wptwbot_get_user_agent();

			$data             = array();
			$data['api_type'] = 'json';
			// @TODO: video support. one of (link, self, image, video, videogif)
			if ( ! empty( $arr['media'] ) ) {
				$data['kind'] = 'image';
			} elseif ( ! empty( $arr['kind'] ) && 'link' === $arr['kind'] ) {
				$data['kind'] = 'link';
			} else {
				$data['kind'] = 'self';
			}
			$data['sr']    = str_replace( 'r/', '', $arr['subreddit'] );

			$data['flair_id']   = '';
			$data['flair_text'] = '';
			if ( ! empty( $arr['subreddit_flair'] ) && false !== strpos( $arr['subreddit_flair'], '##' ) ) {
				list( $data['flair_id'], $data['flair_text'] ) = explode( '##', $arr['subreddit_flair'] );
				if ( 'undefined' === $data['flair_id'] || 'undefined' === $data['flair_text'] ) {
					$data['flair_id']   = '';
					$data['flair_text'] = '';
				}
			}

			$data['title'] = $arr['title'];
			if ( 50 > strlen( $data['title'] ) ) {
				return new WP_Error( 'reddit_error', esc_attr__( 'Reddit title must be more than 50 characters', 'ai-twitterbot' ) );
			}

			if ( 'link' === $data['kind'] ) {
				$data['url'] = $arr['text-link'];
			} else {
				// for self/ image/ video posts.
				$data['text'] = $arr['text'];
			}
			$data['resubmit']    = true;
			$data['sendreplies'] = true;

			if ( empty( $data['sr'] ) ) {
				// no subreddit on reddit!
				$e = sprintf( __( 'Reddit Error %1$d: %2$s', 'ai-twitterbot' ), __LINE__, __( 'No Subreddit found' ) . ';;' . print_R( $data, true ) . ';;' . print_r( $arr, true ) );
				wptwbot_log( __METHOD__, __LINE__, esc_attr( $e ), 1 );
				return new WP_Error( 'reddit_error', esc_attr( $e ) );
			}

			if ( ! empty( $arr['media'] ) ) {
				// upto 20 images per post.
				$_media_id = array();
				foreach ( $arr['media'] as $media ) {
					$media_url  = $this->image_folder_url . $media;
					$image_path = $this->image_folder . $media;
					if ( ! file_exists( $image_path ) ) {
						$e = sprintf( __( 'Reddit Error %1$d: %2$s', 'ai-twitterbot' ), __LINE__, esc_attr( sprintf( __( 'Reddit Image not found: %s', 'ai-twitterbot' ), $media_url ) ) );
						wptwbot_log( __METHOD__, __LINE__, esc_attr( $e ), 1 );
						return new WP_Error( 'reddit_post_error', esc_attr( $e ) );
					}
					wptwbot_log( __METHOD__, __LINE__, 'Reddit Image attach: ' . $media );
					$mime = mime_content_type( $image_path );
					$body = wp_unslash(
						wp_json_encode(
							array(
								'filepath' => basename( $image_path ),
								'mimetype' => $mime,
							)
						)
					);
					// Step 1: Init media. // ?raw_json=1
					$init_upload = wp_remote_post(
						'https://oauth.reddit.com/api/media/asset.json',
						array(
							'headers' => $header,
							'body'    => $body,
							'timeout' => 30,
						)
					);

					if ( is_wp_error( $init_upload ) ) {
						return new WP_Error( 'upload_init_failed', 'Upload initialization failed.' );
					}

					$upload_info = json_decode( wp_remote_retrieve_body( $init_upload ), true );
					wptwbot_log( __METHOD__, __LINE__, esc_attr( 'Reddit Post data: ' . $body .' ;; ' . print_r( $header, true ) ), 1 );
					wptwbot_log( __METHOD__, __LINE__, esc_attr( 'Reddit Post: ' . print_r( $init_upload, true ) ), 1 );
					wptwbot_log( __METHOD__, __LINE__, esc_attr( 'Reddit Post: ' . print_r( $upload_info, true ) ), 1 );
					if ( ! isset( $upload_info['args']['action'] ) ) {
						return new WP_Error( 'upload_data_missing', __( 'Upload URL or fields missing.' ) );
					}

					$upload_url  = $upload_info['args']['action'];
					$fields      = $upload_info['args']['fields'];
					$media_id    = $upload_info['asset']['asset_id']; // 'asset'.
					$_media_id[] = $media_id;

					// Step 2: Build multipart body without CURLFile
					$boundary = '--------------------------' . microtime( true );
					$eol      = "\r\n";
					$body     = '';

					foreach ( $fields as $field ) {
						$body .= "--$boundary$eol";
						$body .= 'Content-Disposition: form-data; name="' . $field['name'] . "\"$eol$eol";
						$body .= $field['value'] . $eol;
					}

					// Step 3: Add the file.
					$file_contents = file_get_contents( $image_path );
					$filename      = basename( $image_path );
					$body         .= "--$boundary$eol";
					$body         .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"$eol";
					$body         .= "Content-Type: $mime$eol$eol";
					$body         .= $file_contents . $eol;
					$body         .= "--$boundary--$eol";

					$this_header                   = $header;
					$this_header['Content-Type']   = 'multipart/form-data; boundary=' . $boundary;
					$this_header['Content-Length'] = strlen( $body );

					$response = wp_remote_post(
						$upload_url,
						array(
							'headers' => $this_header,
							'body'    => $body,
							'timeout' => 30,
						)
					);
					if ( is_wp_error( $response ) ) {
						$e = sprintf( __( 'Reddit Error %1$d: %2$s', 'ai-twitterbot' ), __LINE__, $response->get_error_message() );
						wptwbot_log( __METHOD__, __LINE__, esc_attr( $e ), 1 );
						return new WP_Error( 'reddit_post_error', esc_attr( $e ) );
					}
					if ( ! empty( $response['response']['code'] ) && ! in_array( intval( $response['response']['code'] ), array( 200, 202 ) ) ) {
						$e = sprintf( __( 'Reddit Error %1$d: %2$s', 'ai-twitterbot' ), __LINE__, $response['response']['code'] . ': ' . $response['response']['message'] );
						wptwbot_log( __METHOD__, __LINE__, esc_attr( $e ) . '; URL: ' . $this->image_folder . $media, 1 );
						return new WP_Error( 'reddit_post_error', esc_attr( $e ) );
					}
					$response_code = wp_remote_retrieve_response_code( $response );
					$response_body = wp_remote_retrieve_body( $response );
					$response_body = json_decode( $response_body, true );
					wptwbot_log( __METHOD__, __LINE__, esc_attr( 'Reddit Post: ' . print_r( $response_body, true ) ), 1 );
				}
				if ( ! empty( $_media_id ) ) {
					$data['media_asset_ids'] = '["' . implode( ',', $_media_id ) . '"]';
				}
			} else {
				wptwbot_log( __METHOD__, __LINE__, esc_attr( 'Reddit Post: No media found' ), 1 );
				exit;
			}

			// Step 4: Submit post.
			$this_header                 = $header;
			$this_header['Content-Type'] = 'application/x-www-form-urlencoded';
			$response                    = wp_remote_post(
				'https://oauth.reddit.com/api/submit',
				array(
					'headers' => $this_header,
					'body'    => $data,
				)
			);

			wptwbot_log( __METHOD__, __LINE__, esc_attr( 'Reddit Post: ' . print_r( $this_header, true ) . ';;' . print_r( $data, true ) ), 1 );
			if ( is_wp_error( $response ) ) {
				$e = sprintf( __( 'Reddit Error %1$d: %2$s', 'ai-twitterbot' ), __LINE__, $response->get_error_message() );
				wptwbot_log( __METHOD__, __LINE__, esc_attr( $e ), 1 );
				return new WP_Error( 'reddit_error', esc_attr( $e ) );
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$response_body = json_decode( $response_body, true );
			wptwbot_log( __METHOD__, __LINE__, esc_attr( 'Reddit Post: ' . $response_code . ';;' . print_r( $response_body, true ) ), 1 );

			if ( ! empty( $response_body['json']['errors'] ) ) {
				$message = '';
				foreach ( $response_body['json']['errors'] as $error ) {
					$message .= '[' . $error[0] . '] ' . $error[1] . '; ';
				}
				$e = sprintf( __( 'Reddit Error %1$d: %2$s', 'ai-twitterbot' ), __LINE__, $message );
				wptwbot_log( __METHOD__, __LINE__, esc_attr( $e ), 1 );
				return new WP_Error( 'reddit_error', esc_attr( $e ) );
			}
			$url = $response_body['json']['data']['url'] ?? '';
			if ( empty( $url ) ) {
				$e = sprintf( __( 'Reddit Error %1$d: %2$s', 'ai-twitterbot' ), __LINE__, __( 'No URL found in response' ) . ';;' . print_r( $response_body, true ) );
				wptwbot_log( __METHOD__, __LINE__, esc_attr( $e ), 1 );
				return new WP_Error( 'reddit_error', esc_attr( $e ) );
			}
			return $url;
		}

...
...
