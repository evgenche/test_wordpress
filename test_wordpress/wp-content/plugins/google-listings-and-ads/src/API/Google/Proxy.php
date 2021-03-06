<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\GoogleListingsAndAds\API\Google;

use Automattic\WooCommerce\GoogleListingsAndAds\GoogleHelper;
use Automattic\WooCommerce\GoogleListingsAndAds\Google\Ads\GoogleAdsClient;
use Automattic\WooCommerce\GoogleListingsAndAds\Options\AdsAccountState;
use Automattic\WooCommerce\GoogleListingsAndAds\Options\OptionsAwareInterface;
use Automattic\WooCommerce\GoogleListingsAndAds\Options\OptionsAwareTrait;
use Automattic\WooCommerce\GoogleListingsAndAds\Options\OptionsInterface;
use Automattic\WooCommerce\GoogleListingsAndAds\PluginHelper;
use Automattic\WooCommerce\GoogleListingsAndAds\Value\TosAccepted;
use Automattic\WooCommerce\GoogleListingsAndAds\Vendor\GuzzleHttp\Client;
use DateTime;
use DateTimeZone;
use Exception;
use Google\Ads\GoogleAds\Util\V6\ResourceNames;
use Google\ApiCore\ApiException;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientExceptionInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Class Proxy
 *
 * @package Automattic\WooCommerce\GoogleListingsAndAds\API\Google
 */
class Proxy implements OptionsAwareInterface {

	use ApiExceptionTrait;
	use GoogleHelper;
	use OptionsAwareTrait;
	use PluginHelper;

	/**
	 * @var ContainerInterface
	 */
	protected $container;

	/**
	 * Proxy constructor.
	 *
	 * @param ContainerInterface $container
	 */
	public function __construct( ContainerInterface $container ) {
		$this->container = $container;
	}

	/**
	 * Get merchant IDs associated with the connected Merchant Center account.
	 *
	 * @return int[]
	 * @throws Exception When an Exception is caught.
	 */
	public function get_merchant_ids(): array {
		try {
			/** @var Client $client */
			$client   = $this->container->get( Client::class );
			$result   = $client->get( $this->get_manager_url( 'merchant-accounts' ) );
			$response = json_decode( $result->getBody()->getContents(), true );
			$ids      = [];

			if ( 200 === $result->getStatusCode() && is_array( $response ) ) {
				foreach ( $response as $id ) {
					$ids[] = $id;
				}
			}

			return $ids;
		} catch ( ClientExceptionInterface $e ) {
			do_action( 'woocommerce_gla_guzzle_client_exception', $e, __METHOD__ );

			throw new Exception( $this->client_exception_message( $e, __( 'Error retrieving accounts', 'google-listings-and-ads' ) ) );
		}
	}

	/**
	 * Create a new Merchant Center account.
	 *
	 * @return int
	 * @throws Exception When an Exception is caught or we receive an invalid response.
	 */
	public function create_merchant_account(): int {
		try {
			$user = wp_get_current_user();
			$tos  = $this->mark_tos_accepted( 'google-mc', $user->user_email );
			if ( ! $tos->accepted() ) {
				throw new Exception( __( 'Unable to log accepted TOS', 'google-listings-and-ads' ) );
			}

			$site_url = esc_url_raw( $this->get_site_url() );
			if ( ! wc_is_valid_url( $site_url ) ) {
				throw new Exception( __( 'Invalid site URL.', 'google-listings-and-ads' ) );
			}

			/** @var Client $client */
			$client = $this->container->get( Client::class );
			$result = $client->post(
				$this->get_manager_url( 'create-merchant' ),
				[
					'body' => json_encode(
						[
							'name'       => $this->new_account_name(),
							'websiteUrl' => $site_url,
						]
					),
				]
			);

			$response = json_decode( $result->getBody()->getContents(), true );

			if ( 200 === $result->getStatusCode() && isset( $response['id'] ) ) {
				$id = absint( $response['id'] );
				$this->update_merchant_id( $id );
				return $id;
			}

			do_action( 'woocommerce_gla_guzzle_invalid_response', $response, __METHOD__ );

			$error = $response['message'] ?? __( 'Invalid response when creating account', 'google-listings-and-ads' );
			throw new Exception( $error, $result->getStatusCode() );
		} catch ( ClientExceptionInterface $e ) {
			do_action( 'woocommerce_gla_guzzle_client_exception', $e, __METHOD__ );

			throw new Exception( $this->client_exception_message( $e, __( 'Error creating account', 'google-listings-and-ads' ) ) );
		}
	}

	/**
	 * Link an existing Merchant Center account.
	 *
	 * @param int $id Existing account ID.
	 *
	 * @return int
	 */
	public function link_merchant_account( int $id ): int {
		$this->update_merchant_id( $id );

		return $id;
	}

	/**
	 * Link Merchant Center account to MCA.
	 *
	 * @return bool
	 * @throws Exception When a ClientException is caught or we receive an invalid response.
	 */
	public function link_merchant_to_mca(): bool {
		try {
			/** @var Client $client */
			$client = $this->container->get( Client::class );
			$result = $client->post(
				$this->get_manager_url( 'link-merchant' ),
				[
					'body' => json_encode(
						[
							'accountId' => $this->get_merchant_id(),
						]
					),
				]
			);

			$response = json_decode( $result->getBody()->getContents(), true );

			if ( 200 === $result->getStatusCode() && isset( $response['status'] ) && 'success' === $response['status'] ) {
				return true;
			}

			do_action( 'woocommerce_gla_guzzle_invalid_response', $response, __METHOD__ );

			$error = $response['message'] ?? __( 'Invalid response when linking merchant to MCA', 'google-listings-and-ads' );
			throw new Exception( $error, $result->getStatusCode() );
		} catch ( ClientExceptionInterface $e ) {
			do_action( 'woocommerce_gla_guzzle_client_exception', $e, __METHOD__ );

			throw new Exception( $this->client_exception_message( $e, __( 'Error linking merchant to MCA', 'google-listings-and-ads' ) ) );
		}
	}

	/**
	 * Claim the website for a MCA.
	 *
	 * @param bool $overwrite To enable claim overwriting.
	 * @return bool
	 * @throws Exception When an Exception is caught or we receive an invalid response.
	 */
	public function claim_merchant_website( bool $overwrite = false ): bool {
		try {
			/** @var Client $client */
			$client = $this->container->get( Client::class );
			$result = $client->post(
				$this->get_manager_url( 'claim-website' ),
				[
					'body' => json_encode(
						[
							'accountId' => $this->get_merchant_id(),
							'overwrite' => $overwrite,
						]
					),
				]
			);

			$response = json_decode( $result->getBody()->getContents(), true );

			if ( 200 === $result->getStatusCode() && isset( $response['status'] ) && 'success' === $response['status'] ) {
				do_action( 'woocommerce_gla_site_claim_success', [ 'details' => 'google_manager' ] );
				return true;
			}

			do_action( 'woocommerce_gla_guzzle_invalid_response', $response, __METHOD__ );
			do_action( 'woocommerce_gla_site_claim_failure', [ 'details' => 'google_manager' ] );

			$error = $response['message'] ?? __( 'Invalid response when claiming website', 'google-listings-and-ads' );
			throw new Exception( $error, $result->getStatusCode() );
		} catch ( ClientExceptionInterface $e ) {
			do_action( 'woocommerce_gla_guzzle_client_exception', $e, __METHOD__ );
			do_action( 'woocommerce_gla_site_claim_failure', [ 'details' => 'google_manager' ] );

			throw new Exception( $this->client_exception_message( $e, __( 'Error claiming website', 'google-listings-and-ads' ) ) );
		}
	}


	/**
	 * Get Ads IDs associated with the connected Google account.
	 *
	 * @return int[]
	 * @throws Exception When an ApiException is caught.
	 */
	public function get_ads_account_ids(): array {
		try {
			/** @var GoogleAdsClient $client */
			$client    = $this->container->get( GoogleAdsClient::class );
			$customers = $client->getCustomerServiceClient()->listAccessibleCustomers();
			$ids       = [];

			foreach ( $customers->getResourceNames() as $name ) {
				/** @var string $name */
				$ids[] = $this->parse_ads_id( $name );
			}

			return $ids;
		} catch ( ApiException $e ) {
			do_action( 'woocommerce_gla_ads_client_exception', $e, __METHOD__ );

			// Return an empty list if the user has not signed up to ads yet.
			if ( $this->has_api_exception_error( $e, 'NOT_ADS_USER' ) ) {
				return [];
			}

			/* translators: %s Error message */
			throw new Exception( sprintf( __( 'Error retrieving accounts: %s', 'google-listings-and-ads' ), $e->getBasicMessage() ) );
		}
	}

	/**
	 * Create a new Google Ads account.
	 *
	 * @return array
	 * @throws Exception When a ClientException is caught or we receive an invalid response.
	 */
	public function create_ads_account(): array {
		try {
			$country   = WC()->countries->get_base_country();
			$countries = $this->get_mc_supported_countries();
			if ( ! in_array( $country, $countries, true ) ) {
				throw new Exception( __( 'Store country is not supported', 'google-listings-and-ads' ) );
			}

			$user = wp_get_current_user();
			$tos  = $this->mark_tos_accepted( 'google-ads', $user->user_email );
			if ( ! $tos->accepted() ) {
				throw new Exception( __( 'Unable to log accepted TOS', 'google-listings-and-ads' ) );
			}

			/** @var Client $client */
			$client = $this->container->get( Client::class );
			$result = $client->post(
				$this->get_manager_url( $country . '/create-customer' ),
				[
					'body' => json_encode(
						[
							'descriptive_name' => $this->new_account_name(),
							'currency_code'    => get_woocommerce_currency(),
							'time_zone'        => $this->get_site_timezone_string(),
						]
					),
				]
			);

			$response = json_decode( $result->getBody()->getContents(), true );

			if ( 200 === $result->getStatusCode() && isset( $response['resourceName'] ) ) {
				$id = $this->parse_ads_id( $response['resourceName'] );
				$this->update_ads_id( $id );
				$this->use_store_currency();

				$billing_url = $response['invitationLink'] ?? '';
				$this->update_billing_url( $billing_url );

				return [
					'id'          => $id,
					'billing_url' => $billing_url,
				];
			}

			do_action( 'woocommerce_gla_guzzle_invalid_response', $response, __METHOD__ );

			$error = $response['message'] ?? __( 'Invalid response when creating account', 'google-listings-and-ads' );
			throw new Exception( $error, $result->getStatusCode() );
		} catch ( ClientExceptionInterface $e ) {
			do_action( 'woocommerce_gla_guzzle_client_exception', $e, __METHOD__ );

			throw new Exception( $this->client_exception_message( $e, __( 'Error creating account', 'google-listings-and-ads' ) ) );
		}
	}

	/**
	 * Link an existing Google Ads account.
	 *
	 * @param int $id Existing account ID.
	 *
	 * @return array
	 * @throws Exception When a ClientException is caught or we receive an invalid response.
	 */
	public function link_ads_account( int $id ): array {
		try {
			/** @var Client $client */
			$client = $this->container->get( Client::class );
			$result = $client->post(
				$this->get_manager_url( 'link-customer' ),
				[
					'body' => json_encode(
						[
							'client_customer' => $id,
						]
					),
				]
			);

			$response = json_decode( $result->getBody()->getContents(), true );
			$name     = "customers/{$id}";

			if ( 200 === $result->getStatusCode() && isset( $response['resourceName'] ) && 0 === strpos( $response['resourceName'], $name ) ) {
				$this->update_ads_id( $id );
				$this->request_ads_currency();
				return [ 'id' => $id ];
			}

			do_action( 'woocommerce_gla_guzzle_invalid_response', $response, __METHOD__ );

			$error = $response['message'] ?? __( 'Invalid response when linking account', 'google-listings-and-ads' );
			throw new Exception( $error, $result->getStatusCode() );
		} catch ( ClientExceptionInterface $e ) {
			do_action( 'woocommerce_gla_guzzle_client_exception', $e, __METHOD__ );

			throw new Exception( $this->client_exception_message( $e, __( 'Error linking account', 'google-listings-and-ads' ) ) );
		}
	}

	/**
	 * Get the connected ads account.
	 *
	 * @return array
	 */
	public function get_connected_ads_account(): array {
		$id = $this->options->get( OptionsInterface::ADS_ID );

		// Retrieve account currency if we haven't done so previously.
		if ( $id && ! $this->options->get( OptionsInterface::ADS_ACCOUNT_CURRENCY ) ) {
			$this->request_ads_currency();
		}

		$status = [
			'id'       => $id,
			'currency' => $this->options->get( OptionsInterface::ADS_ACCOUNT_CURRENCY ),
			'status'   => $id ? 'connected' : 'disconnected',
		];

		$state      = $this->container->get( AdsAccountState::class );
		$incomplete = $state->last_incomplete_step();
		if ( ! empty( $incomplete ) ) {
			$status['status'] = 'incomplete';
			$status['step']   = $incomplete;
		}

		$status += $state->get_step_data( 'set_id' );

		return $status;
	}

	/**
	 * Disconnect the connected ads account.
	 */
	public function disconnect_ads_account() {
		$this->update_ads_id( 0 );
	}

	/**
	 * Determine whether the TOS have been accepted.
	 *
	 * @param string $service Name of service.
	 *
	 * @return TosAccepted
	 */
	public function check_tos_accepted( string $service ): TosAccepted {
		// todo: see about using the WooCommerce Services code here
		try {
			/** @var Client $client */
			$client = $this->container->get( Client::class );
			$result = $client->get( $this->get_tos_url( $service ) );

			return new TosAccepted( 200 === $result->getStatusCode(), $result->getBody()->getContents() );
		} catch ( ClientExceptionInterface $e ) {
			do_action( 'woocommerce_gla_guzzle_client_exception', $e, __METHOD__ );

			return new TosAccepted( false, $e->getMessage() );
		}
	}

	/**
	 * Record TOS acceptance for a particular email address.
	 *
	 * @param string $service Name of service.
	 * @param string $email
	 *
	 * @return TosAccepted
	 */
	public function mark_tos_accepted( string $service, string $email ): TosAccepted {
		// todo: see about using WooCommerce Services code here.
		try {
			/** @var Client $client */
			$client = $this->container->get( Client::class );
			$result = $client->post(
				$this->get_tos_url( $service ),
				[
					'body' => json_encode(
						[
							'email' => $email,
						]
					),
				]
			);

			return new TosAccepted(
				200 === $result->getStatusCode(),
				$result->getBody()->getContents() ?? $result->getReasonPhrase()
			);
		} catch ( ClientExceptionInterface $e ) {
			do_action( 'woocommerce_gla_guzzle_client_exception', $e, __METHOD__ );

			return new TosAccepted( false, $e->getMessage() );
		}
	}

	/**
	 * Get the TOS endpoint URL
	 *
	 * @param string $service Name of service.
	 *
	 * @return string
	 */
	protected function get_tos_url( string $service ): string {
		$url = $this->container->get( 'connect_server_root' ) . 'tos';
		return $service ? trailingslashit( $url ) . $service : $url;
	}

	/**
	 * Get the manager endpoint URL
	 *
	 * @param string $name Resource name.
	 *
	 * @return string
	 */
	protected function get_manager_url( string $name = '' ): string {
		$url = $this->container->get( 'connect_server_root' ) . 'google/manager';
		return $name ? trailingslashit( $url ) . $name : $url;
	}

	/**
	 * Convert ads ID from a resource name to an int.
	 *
	 * @param string $name Resource name containing ID number.
	 *
	 * @return int
	 */
	protected function parse_ads_id( string $name ): int {
		return absint( str_replace( 'customers/', '', $name ) );
	}

	/**
	 * Get the Merchant Center ID.
	 *
	 * @return int
	 */
	protected function get_merchant_id(): int {
		return $this->options->get( OptionsInterface::MERCHANT_ID );
	}

	/**
	 * Update the Merchant Center ID to use for requests.
	 *
	 * @param int $id  Merchant ID number.
	 *
	 * @return bool
	 */
	protected function update_merchant_id( int $id ): bool {
		return $this->options->update( OptionsInterface::MERCHANT_ID, $id );
	}

	/**
	 * Update the Ads ID to use for requests.
	 *
	 * @param int $id Ads ID number.
	 *
	 * @return bool
	 */
	protected function update_ads_id( int $id ): bool {
		return $this->options->update( OptionsInterface::ADS_ID, $id );
	}

	/**
	 * Save the Ads account currency to the same value as the Store currency.
	 *
	 * @since 1.1.0
	 *
	 * @return boolean
	 */
	protected function use_store_currency(): bool {
		return $this->options->update( OptionsInterface::ADS_ACCOUNT_CURRENCY, get_woocommerce_currency() );
	}

	/**
	 * Request the Ads Account currency, and cache it as an option.
	 *
	 * @since 1.1.0
	 *
	 * @return boolean
	 */
	protected function request_ads_currency(): bool {
		if ( $use_store_currency ) {
			$currency = get_woocommerce_currency();
		} else {
			try {
				/** @var GoogleAdsClient $client */
				$client   = $this->container->get( GoogleAdsClient::class );
				$resource = ResourceNames::forCustomer( $this->options->get( OptionsInterface::ADS_ID ) );
				$customer = $client->getCustomerServiceClient()->getCustomer( $resource );
				$currency = $customer->getCurrencyCode();
			} catch ( ApiException $e ) {
				do_action( 'woocommerce_gla_ads_client_exception', $e, __METHOD__ );
				$currency = null;
			}
		}

		return $this->options->update( OptionsInterface::ADS_ACCOUNT_CURRENCY, $currency );
	}

	/**
	 * Update the billing flow URL so we can retrieve it again later.
	 *
	 * @param string $url Billing flow URL.
	 *
	 * @return bool
	 */
	protected function update_billing_url( string $url ): bool {
		return $this->options->update( OptionsInterface::ADS_BILLING_URL, $url );
	}

	/**
	 * Generate a descriptive name for a new account.
	 *
	 * @return string
	 */
	protected function new_account_name(): string {
		return get_bloginfo( 'name' );
	}

	/**
	 * Get a timezone string from WP Settings.
	 *
	 * @return string
	 * @throws Exception If the DateTime instantiation fails.
	 */
	protected function get_site_timezone_string(): string {
		$timezone = wp_timezone_string();

		// Convert a timezone offset to the closest match.
		if ( false !== strpos( $timezone, ':' ) ) {
			list( $hours, $minutes ) = explode( ':', $timezone );

			$dst      = (int) ( new DateTime( 'now', new DateTimeZone( $timezone ) ) )->format( 'I' );
			$seconds  = $hours * 60 * 60 + $minutes * 60;
			$tz_name  = timezone_name_from_abbr( '', $seconds, $dst );
			$timezone = $tz_name !== false ? $tz_name : date_default_timezone_get();
		}

		if ( 'UTC' === $timezone ) {
			$timezone = 'Etc/GMT';
		}

		return $timezone;
	}
}
