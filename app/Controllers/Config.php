<?php

namespace App\Controllers;

use app\Libraries\Barcode_lib;
use app\Libraries\Mailchimp_lib;
use app\Libraries\Receiving_lib;
use app\Libraries\Sale_lib;
use app\Libraries\Tax_lib;

use app\Models\Appconfig;
use app\Models\Attribute;
use app\Models\Customer_rewards;
use app\Models\Dinner_table;
use app\Models\Module;
use app\Models\Enums\Rounding_mode;
use app\Models\Stock_location;
use app\Models\Tax;

use CodeIgniter\Encryption\Encryption;
use CodeIgniter\Encryption\EncrypterInterface;
use DirectoryIterator;
use NumberFormatter;
use ReflectionException;

/**
 *
 *
 * @property barcode_lib barcode_lib
 * @property mailchimp_lib mailchimp_lib
 * @property receiving_lib receiving_lib
 * @property sale_lib sale_lib
 * @property tax_lib tax_lib
 * @property encryption encryption
 * @property encrypterinterface encrypter
 * @property appconfig appconfig
 * @property attribute attribute
 * @property customer_rewards customer_rewards
 * @property dinner_table dinner_table
 * @property module module
 * @property rounding_mode rounding_mode
 * @property stock_location stock_location
 * @property tax tax
 * @property upload upload
 * 
 */
class Config extends Secure_Controller
{
	public function __construct()
	{
		parent::__construct('config');

		$this->barcode_lib = new Barcode_lib();
		$this->sale_lib = new Sale_lib();
		$this->receiving_lib = new receiving_lib();
		$this->tax_lib = new Tax_lib();
		
		$this->attribute = model('Attribute');
		$this->customer_rewards = model('Customer_rewards');
		$this->dinner_table = model('Dinner_table');
		$this->module = model('Module');
		$this->rounding_mode = model('Rounding_mode');
		$this->stock_location = model('Stock_location');
		$this->tax = model('Tax');

		$this->encryption = new Encryption();	//TODO: Is this the correct way to load the encryption service now?
		$this->encrypter = $this->encryption->initialize();
	}

	/*
	 * This function loads all the licenses starting with the first one being OSPOS one
	 */
	private function _licenses(): array    //TODO: remove hungarian notation.  Super long function.  Perhaps we need to refactor out functions?
	{
		$i = 0;
		$bower = FALSE;
		$composer = FALSE;
		$license = [];

		$license[$i]['title'] = 'Open Source Point Of Sale ' . config('OSPOSConfig')->application_version;

		if(file_exists('license/LICENSE'))
		{
			$license[$i]['text'] = file_get_contents('license/LICENSE', NULL, NULL, 0, 2000);
		}
		else
		{
			$license[$i]['text'] = 'LICENSE file must be in OSPOS license directory. You are not allowed to use OSPOS application until the distribution copy of LICENSE file is present.';
		}

		$dir = new DirectoryIterator('license');	// read all the files in the dir license

		foreach($dir as $fileinfo)	//TODO: $fileinfo doesn't match our variable naming convention
		{
			// license files must be in couples: .version (name & version) & .license (license text)
			if($fileinfo->isFile())
			{
				if($fileinfo->getExtension() == 'version')
				{
					++$i;

					$basename = 'license/' . $fileinfo->getBasename('.version');

					$license[$i]['title'] = file_get_contents($basename . '.version', NULL, NULL, 0, 100);

					$license_text_file = $basename . '.license';

					if(file_exists($license_text_file))
					{
						$license[$i]['text'] = file_get_contents($license_text_file , NULL, NULL, 0, 2000);
					}
					else
					{
						$license[$i]['text'] = $license_text_file . ' file is missing';
					}
				}
				elseif($fileinfo->getBasename() == 'bower.LICENSES')
				{
					// set a flag to indicate that the JS Plugin bower.LICENSES file is available and needs to be attached at the end
					$bower = TRUE;
				}
				elseif($fileinfo->getBasename() == 'composer.LICENSES')
				{
					// set a flag to indicate that the composer.LICENSES file is available and needs to be attached at the end
					$composer = TRUE;
				}
			}
		}

		// attach the licenses from the LICENSES file generated by bower
		if($composer)
		{
			++$i;
			$license[$i]['title'] = 'Composer Libraries';
			$license[$i]['text'] = '';

			$file = file_get_contents('license/composer.LICENSES');
			$array = json_decode($file, TRUE);

			foreach($array as $key => $val)
			{
				if(is_array($val) && $key == 'dependencies')
				{
					foreach($val as $key1 => $val1)
					{
						if(is_array($val1))
						{
							$license[$i]['text'] .= 'component: ' . $key1 . "\n";	//TODO: Duplicated Code

							foreach($val1 as $key2 => $val2)
							{
								if(is_array($val2))
								{
									$license[$i]['text'] .= $key2 . ': ';

									foreach($val2 as $key3 => $val3)
									{
										$license[$i]['text'] .= $val3 . ' ';
									}

									$license[$i]['text'] .= "\n";
								}
								else
								{
									$license[$i]['text'] .= $key2 . ': ' . $val2 . "\n";
								}
							}

							$license[$i]['text'] .= "\n";
						}
						else
						{
							$license[$i]['text'] .= $key1 . ': ' . $val1 . "\n";
						}
					}
				}
			}
		}

		// attach the licenses from the LICENSES file generated by bower
		if($bower)
		{
			++$i;
			$license[$i]['title'] = 'JS Plugins';
			$license[$i]['text'] = '';

			$file = file_get_contents('license/bower.LICENSES');
			$array = json_decode($file, TRUE);

			foreach($array as $key => $val)
			{
				if(is_array($val))
				{
					$license[$i]['text'] .= 'component: ' . $key . "\n";	//TODO: Duplicated Code.

					foreach($val as $key1 => $val1)
					{
						if(is_array($val1))
						{
							$license[$i]['text'] .= $key1 . ': ';

							foreach($val1 as $key2 => $val2)
							{
								$license[$i]['text'] .= $val2 . ' ';
							}

							$license[$i]['text'] .= "\n";
						}
						else
						{
							$license[$i]['text'] .= $key1 . ': ' . $val1 . "\n";
						}
					}

					$license[$i]['text'] .= "\n";
				}
			}
		}

		return $license;
	}

	/*
	 * This function loads all the available themes in the dist/bootswatch directory
	 */
	private function _themes(): array	//TODO: Hungarian notation
	{
		$themes = [];

		// read all themes in the dist folder
		$dir = new DirectoryIterator('dist/bootswatch');

		foreach($dir as $dirinfo)	//TODO: $dirinfo doesn't follow naming convention
		{
			if($dirinfo->isDir() && !$dirinfo->isDot() && $dirinfo->getFileName() != 'fonts')
			{
				$file = $dirinfo->getFileName();
				$themes[$file] = ucfirst($file);
			}
		}

		asort($themes);

		return $themes;
	}

	public function index(): void
	{
		$data['stock_locations'] = $this->stock_location->get_all()->getResultArray();
		$data['dinner_tables'] = $this->dinner_table->get_all()->getResultArray();
		$data['customer_rewards'] = $this->customer_rewards->get_all()->getResultArray();
		$data['support_barcode'] = $this->barcode_lib->get_list_barcodes();
		$data['logo_exists'] = config('OSPOS')->company_logo != '';
		$data['line_sequence_options'] = $this->sale_lib->get_line_sequence_options();
		$data['register_mode_options'] = $this->sale_lib->get_register_mode_options();
		$data['invoice_type_options'] = $this->sale_lib->get_invoice_type_options();
		$data['rounding_options'] = rounding_mode::get_rounding_options();
		$data['tax_code_options'] = $this->tax_lib->get_tax_code_options();
		$data['tax_category_options'] = $this->tax_lib->get_tax_category_options();
		$data['tax_jurisdiction_options'] = $this->tax_lib->get_tax_jurisdiction_options();
		$data['show_office_group'] = $this->module->get_show_office_group();
		$data['currency_code'] = config('OSPOS')->currency_code;

		// load all the license statements, they are already XSS cleaned in the private function
		$data['licenses'] = $this->_licenses();

		// load all the themes, already XSS cleaned in the private function
		$data['themes'] = $this->_themes();

		//Load General related fields
		$image_allowed_types = ['jpg','jpeg','gif','svg','webp','bmp','png','tif','tiff'];
		$data['image_allowed_types'] = array_combine($image_allowed_types,$image_allowed_types);

		$data['selected_image_allowed_types'] = explode('|', config('OSPOS')->image_allowed_types);

		//Load Integrations Related fields
		$data['mailchimp']	= [];

		if($this->_check_encryption())	//TODO: Hungarian notation
		{
			$data['mailchimp']['api_key'] = $this->encrypter->decrypt(config('OSPOS')->mailchimp_api_key);
			$data['mailchimp']['list_id'] = $this->encrypter->decrypt(config('OSPOS')->mailchimp_list_id);
		}
		else
		{
			$data['mailchimp']['api_key'] = '';
			$data['mailchimp']['list_id'] = '';
		}

		// load mailchimp lists associated to the given api key, already XSS cleaned in the private function
		$data['mailchimp']['lists'] = $this->_mailchimp();

		echo view('configs/manage', $data);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_info(): void
	{
		$upload_success = $this->_handle_logo_upload();
		$upload_data = $this->upload->data();

		$batch_save_data = [
			'company' => $this->request->getPost('company'),
			'address' => $this->request->getPost('address'),
			'phone' => $this->request->getPost('phone'),
			'email' => $this->request->getPost('email'),
			'fax' => $this->request->getPost('fax'),
			'website' => $this->request->getPost('website'),
			'return_policy' => $this->request->getPost('return_policy')
		];

		if(!empty($upload_data['orig_name']))
		{
			// XSS file image sanity check
			if($upload_data['raw_name'] === TRUE)
			{
				$batch_save_data['company_logo'] = $upload_data['raw_name'] . $upload_data['file_ext'];
			}
		}

		$result = $this->appconfig->batch_save($batch_save_data);
		$success = $upload_success && $result;
		$message = lang('Config.saved_' . ($success ? '' : 'un') . 'successfully');
		$message = $upload_success ? $message : strip_tags($this->upload->display_errors());

		echo json_encode(['success' => $success, 'message' => $message]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_general(): void
	{
		$batch_save_data = [
			'theme' => $this->request->getPost('theme'),
			'login_form' => $this->request->getPost('login_form'),
			'default_sales_discount_type' => $this->request->getPost('default_sales_discount_type') != NULL,
			'default_sales_discount' => $this->request->getPost('default_sales_discount'),
			'default_receivings_discount_type' => $this->request->getPost('default_receivings_discount_type') != NULL,
			'default_receivings_discount' => $this->request->getPost('default_receivings_discount'),
			'enforce_privacy' => $this->request->getPost('enforce_privacy'),
			'receiving_calculate_average_price' => $this->request->getPost('receiving_calculate_average_price') != NULL,
			'lines_per_page' => $this->request->getPost('lines_per_page'),
			'notify_horizontal_position' => $this->request->getPost('notify_horizontal_position'),
			'notify_vertical_position' => $this->request->getPost('notify_vertical_position'),
			'image_max_width' => $this->request->getPost('image_max_width'),
			'image_max_height' => $this->request->getPost('image_max_height'),
			'image_max_size' => $this->request->getPost('image_max_size'),
			'image_allowed_types' => implode('|', $this->request->getPost('image_allowed_types')),
			'gcaptcha_enable' => $this->request->getPost('gcaptcha_enable') != NULL,
			'gcaptcha_secret_key' => $this->request->getPost('gcaptcha_secret_key'),
			'gcaptcha_site_key' => $this->request->getPost('gcaptcha_site_key'),
			'suggestions_first_column' => $this->request->getPost('suggestions_first_column'),
			'suggestions_second_column' => $this->request->getPost('suggestions_second_column'),
			'suggestions_third_column' => $this->request->getPost('suggestions_third_column'),
			'giftcard_number' => $this->request->getPost('giftcard_number'),
			'derive_sale_quantity' => $this->request->getPost('derive_sale_quantity') != NULL,
			'multi_pack_enabled' => $this->request->getPost('multi_pack_enabled') != NULL,
			'include_hsn' => $this->request->getPost('include_hsn') != NULL,
			'category_dropdown' => $this->request->getPost('category_dropdown') != NULL
		];

		$this->module->set_show_office_group($this->request->getPost('show_office_group') != NULL);

		if($batch_save_data['category_dropdown'] == 1)
		{
			$definition_data['definition_name'] = 'ospos_category';
			$definition_data['definition_flags'] = 0;
			$definition_data['definition_type'] = 'DROPDOWN';
			$definition_data['definition_id'] = CATEGORY_DEFINITION_ID;
			$definition_data['deleted'] = 0;

			$this->attribute->save_definition($definition_data, CATEGORY_DEFINITION_ID);
		}
		else if($batch_save_data['category_dropdown'] == NO_DEFINITION_ID)
		{
			$this->attribute->delete_definition(CATEGORY_DEFINITION_ID);
		}

		$success = $this->appconfig->batch_save($batch_save_data);

		echo json_encode (['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	public function ajax_check_number_locale(): void
	{
		$number_locale = $this->request->getPost('number_locale');
		$save_number_locale = $this->request->getPost('save_number_locale');

		$fmt = new NumberFormatter($number_locale, NumberFormatter::CURRENCY);
		if($number_locale != $save_number_locale)
		{
			$currency_symbol = $fmt->getSymbol(NumberFormatter::CURRENCY_SYMBOL);
			$currency_code = $fmt->getTextAttribute(NumberFormatter::CURRENCY_CODE);
			$save_number_locale = $number_locale;
		}
		else
		{
			$currency_symbol = empty($this->request->getPost('currency_symbol')) ? $fmt->getSymbol(NumberFormatter::CURRENCY_SYMBOL) : $this->request->getPost('currency_symbol');
			$currency_code = empty($this->request->getPost('currency_code')) ? $fmt->getTextAttribute(NumberFormatter::CURRENCY_CODE) : $this->request->getPost('currency_code');
		}

		if($this->request->getPost('thousands_separator') == 'false')
		{
			$fmt->setAttribute(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, '');
		}

		$fmt->setSymbol(NumberFormatter::CURRENCY_SYMBOL, $currency_symbol);
		$number_local_example = $fmt->format(1234567890.12300);

		echo json_encode([
			'success' => $number_local_example != FALSE,
			'save_number_locale' => $save_number_locale,
			'number_locale_example' => $number_local_example,
			'currency_symbol' => $currency_symbol,
			'currency_code' => $currency_code,
		]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_locale(): void
	{
		$exploded = explode(":", $this->request->getPost('language'));
		$batch_save_data = [
			'currency_symbol' => $this->request->getPost('currency_symbol'),
			'currency_code' => $this->request->getPost('currency_code'),
			'language_code' => $exploded[0],
			'language' => $exploded[1],
			'timezone' => $this->request->getPost('timezone'),
			'dateformat' => $this->request->getPost('dateformat'),
			'timeformat' => $this->request->getPost('timeformat'),
			'thousands_separator' => !empty($this->request->getPost('thousands_separator')),
			'number_locale' => $this->request->getPost('number_locale'),
			'currency_decimals' => $this->request->getPost('currency_decimals'),
			'tax_decimals' => $this->request->getPost('tax_decimals'),
			'quantity_decimals' => $this->request->getPost('quantity_decimals'),
			'country_codes' => $this->request->getPost('country_codes'),
			'payment_options_order' => $this->request->getPost('payment_options_order'),
			'date_or_time_format' => $this->request->getPost('date_or_time_format'),
			'cash_decimals' => $this->request->getPost('cash_decimals'),
			'cash_rounding_code' => $this->request->getPost('cash_rounding_code'),
			'financial_year' => $this->request->getPost('financial_year')
		];

		$success = $this->appconfig->batch_save($batch_save_data);

		echo json_encode(['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_email(): void
	{
		$password = '';

		if($this->_check_encryption())
		{
			$password = $this->encrypter->encrypt($this->request->getPost('smtp_pass'));
		}

		$batch_save_data = [
			'protocol' => $this->request->getPost('protocol'),
			'mailpath' => $this->request->getPost('mailpath'),
			'smtp_host' => $this->request->getPost('smtp_host'),
			'smtp_user' => $this->request->getPost('smtp_user'),
			'smtp_pass' => $password,
			'smtp_port' => $this->request->getPost('smtp_port'),
			'smtp_timeout' => $this->request->getPost('smtp_timeout'),
			'smtp_crypto' => $this->request->getPost('smtp_crypto')
		];

		$success = $this->appconfig->batch_save($batch_save_data);

		echo json_encode (['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_message(): void
	{
		$password = '';

		if($this->_check_encryption())
		{
			$password = $this->encrypter->encrypt($this->request->getPost('msg_pwd'));
		}

		$batch_save_data = [
			'msg_msg' => $this->request->getPost('msg_msg'),
			'msg_uid' => $this->request->getPost('msg_uid'),
			'msg_pwd' => $password,
			'msg_src' => $this->request->getPost('msg_src')
		];

		$success = $this->appconfig->batch_save($batch_save_data);

		echo json_encode(['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	/*
	 * This function fetches all the available lists from Mailchimp for the given API key
	 */
	private function _mailchimp(string $api_key = ''): array	//TODO: Hungarian notation
	{
		$this->mailchimp_lib = new Mailchimp_lib(['api_key' => $api_key]);

		$result = [];

		if(($lists = $this->mailchimp_lib->getLists()) !== FALSE)
		{
			if(is_array($lists) && !empty($lists['lists']) && is_array($lists['lists']))
			{
				foreach($lists['lists'] as $list)
				{
					$result[$list['id']] = $list['name'] . ' [' . $list['stats']['member_count'] . ']';
				}
			}
		}

		return $result;
	}

	/*
	 AJAX call from mailchimp config form to fetch the Mailchimp lists when a valid API key is inserted
	 */
	public function ajax_check_mailchimp_api_key(): void
	{
		// load mailchimp lists associated to the given api key, already XSS cleaned in the private function
		$lists = $this->_mailchimp($this->request->getPost('mailchimp_api_key'));
		$success = count($lists) > 0 ? TRUE : FALSE;	//TODO: This can be replaced with `count($lists) > 0;`

		echo json_encode ([
			'success' => $success,
			'message' => lang('Config.mailchimp_key_' . ($success ? '' : 'un') . 'successfully'),
			'mailchimp_lists' => $lists
		]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_mailchimp(): void
	{
		$api_key = '';
		$list_id = '';

		if($this->_check_encryption())	//TODO: Hungarian notation
		{
			$api_key = $this->encrypter->encrypt($this->request->getPost('mailchimp_api_key'));
			$list_id = $this->encrypter->encrypt($this->request->getPost('mailchimp_list_id'));
		}

		$batch_save_data = ['mailchimp_api_key' => $api_key, 'mailchimp_list_id' => $list_id];

		$success = $this->appconfig->batch_save($batch_save_data);

		echo json_encode(['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	public function ajax_stock_locations(): void
	{
		$stock_locations = $this->stock_location->get_all()->getResultArray();

		echo view('partial/stock_locations', ['stock_locations' => $stock_locations]);
	}

	public function ajax_dinner_tables(): void
	{
		$dinner_tables = $this->dinner_table->get_all()->getResultArray();

		echo view('partial/dinner_tables', ['dinner_tables' => $dinner_tables]);
	}

	public function ajax_tax_categories(): void
	{
		$tax_categories = $this->tax->get_all_tax_categories()->getResultArray();

		echo view('partial/tax_categories', ['tax_categories' => $tax_categories]);
	}

	public function ajax_customer_rewards(): void
	{
		$customer_rewards = $this->customer_rewards->get_all()->getResultArray();

		echo view('partial/customer_rewards', ['customer_rewards' => $customer_rewards]);
	}

	private function _clear_session_state(): void	//TODO: Hungarian notation
	{
		$this->sale_lib->clear_sale_location();
		$this->sale_lib->clear_table();
		$this->sale_lib->clear_all();
		$this->receiving_lib = new Receiving_lib();
		$this->receiving_lib->clear_stock_source();
		$this->receiving_lib->clear_stock_destination();
		$this->receiving_lib->clear_all();
	}

	public function save_locations(): void
	{
		$this->db->transStart();

		$not_to_delete = [];
		foreach($this->request->getPost() as $key => $value)
		{
			if(strstr($key, 'stock_location'))
			{
				// save or update
				foreach ($value as $location_id => $location_name)
				{
					$location_data = ['location_name' => $location_name];
					if($this->stock_location->save_value($location_data, $location_id))
					{
						$location_id = $this->stock_location->get_location_id($location_name);
						$not_to_delete[] = $location_id;
						$this->_clear_session_state();
					}
				}
			}
		}

		// all locations not available in post will be deleted now
		$deleted_locations = $this->stock_location->get_all()->getResultArray();

		foreach($deleted_locations as $location => $location_data)
		{
			if(!in_array($location_data['location_id'], $not_to_delete))
			{
				$this->stock_location->delete($location_data['location_id']);
			}
		}

		$this->db->transComplete();

		$success = $this->db->transStatus();

		echo json_encode (['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_tables(): void
	{
		$this->db->transStart();

		$dinner_table_enable = $this->request->getPost('dinner_table_enable') != NULL;

		$this->appconfig->save(['dinner_table_enable' => $dinner_table_enable]);

		if($dinner_table_enable)
		{
			$not_to_delete = [];
			foreach($this->request->getPost() as $key => $value)
			{
				if(strstr($key, 'dinner_table') && $key != 'dinner_table_enable')
				{
					$dinner_table_id = preg_replace("/.*?_(\d+)$/", "$1", $key);
					$not_to_delete[] = $dinner_table_id;

					// save or update
					$table_data = ['name' => $value];
					if($this->dinner_table->save_value($table_data, $dinner_table_id))
					{
						$this->_clear_session_state();	//TODO: Remove hungarian notation.
					}
				}
			}

			// all tables not available in post will be deleted now
			$deleted_tables = $this->dinner_table->get_all()->getResultArray();

			foreach($deleted_tables as $dinner_tables => $table)
			{
				if(!in_array($table['dinner_table_id'], $not_to_delete))
				{
					$this->dinner_table->delete($table['dinner_table_id']);
				}
			}
		}

		$this->db->transComplete();

		$success = $this->db->transStatus();

		echo json_encode (['success' => $success,'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	public function save_tax(): void
	{
		$this->db->transStart();

		$batch_save_data = [
			'default_tax_1_rate' => parse_tax($this->request->getPost('default_tax_1_rate')),
			'default_tax_1_name' => $this->request->getPost('default_tax_1_name'),
			'default_tax_2_rate' => parse_tax($this->request->getPost('default_tax_2_rate')),
			'default_tax_2_name' => $this->request->getPost('default_tax_2_name'),
			'tax_included' => $this->request->getPost('tax_included') != NULL,
			'use_destination_based_tax' => $this->request->getPost('use_destination_based_tax') != NULL,
			'default_tax_code' => $this->request->getPost('default_tax_code'),
			'default_tax_category' => $this->request->getPost('default_tax_category'),
			'default_tax_jurisdiction' => $this->request->getPost('default_tax_jurisdiction'),
			'tax_id' => $this->request->getPost('tax_id')
		];

		$success = $this->appconfig->batch_save($batch_save_data);

		$this->db->transComplete();

		$success &= $this->db->transStatus();

		$message = lang('Config.saved_' . ($success ? '' : 'un') . 'successfully');

		echo json_encode (['success' => $success, 'message' => $message]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_rewards(): void
	{
		$this->db->transStart();

		$customer_reward_enable = $this->request->getPost('customer_reward_enable') != NULL;

		$this->appconfig->save(['customer_reward_enable' => $customer_reward_enable]);

		if($customer_reward_enable)
		{
			$not_to_delete = [];
			$array_save = [];
			foreach($this->request->getPost() as $key => $value)
			{
				if(strstr($key, 'customer_reward') && $key != 'customer_reward_enable')
				{
					$customer_reward_id = preg_replace("/.*?_(\d+)$/", "$1", $key);
					$not_to_delete[] = $customer_reward_id;
					$array_save[$customer_reward_id]['package_name'] = $value;
				}
				elseif(strstr($key, 'reward_points'))
				{
					$customer_reward_id = preg_replace("/.*?_(\d+)$/", "$1", $key);
					$array_save[$customer_reward_id]['points_percent'] = $value;
				}
			}

			if(!empty($array_save))
			{
				foreach($array_save as $key => $value)
				{
					// save or update
					$package_data = ['package_name' => $value['package_name'], 'points_percent' => $value['points_percent']];
					$this->customer_rewards->save_value($package_data, $key);	//TODO: reflection exception
				}
			}

			// all packages not available in post will be deleted now
			$deleted_packages = $this->customer_rewards->get_all()->getResultArray();

			foreach($deleted_packages as $customer_rewards => $reward_category)
			{
				if(!in_array($reward_category['package_id'], $not_to_delete))
				{
					$this->customer_rewards->delete($reward_category['package_id']);
				}
			}
		}

		$this->db->transComplete();

		$success = $this->db->transStatus();

		echo json_encode (['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_barcode(): void
	{
		$batch_save_data = [
			'barcode_type' => $this->request->getPost('barcode_type'),
			'barcode_width' => $this->request->getPost('barcode_width'),
			'barcode_height' => $this->request->getPost('barcode_height'),
			'barcode_font' => $this->request->getPost('barcode_font'),
			'barcode_font_size' => $this->request->getPost('barcode_font_size'),
			'barcode_first_row' => $this->request->getPost('barcode_first_row'),
			'barcode_second_row' => $this->request->getPost('barcode_second_row'),
			'barcode_third_row' => $this->request->getPost('barcode_third_row'),
			'barcode_num_in_row' => $this->request->getPost('barcode_num_in_row'),
			'barcode_page_width' => $this->request->getPost('barcode_page_width'),
			'barcode_page_cellspacing' => $this->request->getPost('barcode_page_cellspacing'),
			'barcode_generate_if_empty' => $this->request->getPost('barcode_generate_if_empty') != NULL,
			'allow_duplicate_barcodes' => $this->request->getPost('allow_duplicate_barcodes') != NULL,
			'barcode_content' => $this->request->getPost('barcode_content'),
			'barcode_formats' => json_encode($this->request->getPost('barcode_formats'))
		];

		$success = $this->appconfig->batch_save($batch_save_data);

		echo json_encode (['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_receipt(): void
	{
		$batch_save_data = [
			'receipt_template' => $this->request->getPost('receipt_template'),
			'receipt_font_size' => $this->request->getPost('receipt_font_size'),
			'print_delay_autoreturn' => $this->request->getPost('print_delay_autoreturn'),
			'email_receipt_check_behaviour' => $this->request->getPost('email_receipt_check_behaviour'),
			'print_receipt_check_behaviour' => $this->request->getPost('print_receipt_check_behaviour'),
			'receipt_show_company_name' => $this->request->getPost('receipt_show_company_name') != NULL,
			'receipt_show_taxes' => ($this->request->getPost('receipt_show_taxes') != NULL),
			'receipt_show_tax_ind' => ($this->request->getPost('receipt_show_tax_ind') != NULL),
			'receipt_show_total_discount' => $this->request->getPost('receipt_show_total_discount') != NULL,
			'receipt_show_description' => $this->request->getPost('receipt_show_description') != NULL,
			'receipt_show_serialnumber' => $this->request->getPost('receipt_show_serialnumber') != NULL,
			'print_silently' => $this->request->getPost('print_silently') != NULL,
			'print_header' => $this->request->getPost('print_header') != NULL,
			'print_footer' => $this->request->getPost('print_footer') != NULL,
			'print_top_margin' => $this->request->getPost('print_top_margin'),
			'print_left_margin' => $this->request->getPost('print_left_margin'),
			'print_bottom_margin' => $this->request->getPost('print_bottom_margin'),
			'print_right_margin' => $this->request->getPost('print_right_margin')
		];

		$success = $this->appconfig->batch_save($batch_save_data);

		echo json_encode (['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function save_invoice(): void
	{
		$batch_save_data = [
			'invoice_enable' => $this->request->getPost('invoice_enable') != NULL,
			'sales_invoice_format' => $this->request->getPost('sales_invoice_format'),
			'sales_quote_format' => $this->request->getPost('sales_quote_format'),
			'recv_invoice_format' => $this->request->getPost('recv_invoice_format'),
			'invoice_default_comments' => $this->request->getPost('invoice_default_comments'),
			'invoice_email_message' => $this->request->getPost('invoice_email_message'),
			'line_sequence' => $this->request->getPost('line_sequence'),
			'last_used_invoice_number' => $this->request->getPost('last_used_invoice_number'),
			'last_used_quote_number' => $this->request->getPost('last_used_quote_number'),
			'quote_default_comments' => $this->request->getPost('quote_default_comments'),
			'work_order_enable' => $this->request->getPost('work_order_enable') != NULL,
			'work_order_format' => $this->request->getPost('work_order_format'),
			'last_used_work_order_number' => $this->request->getPost('last_used_work_order_number'),
			'invoice_type' => $this->request->getPost('invoice_type')
		];

		$success = $this->appconfig->batch_save($batch_save_data);

		// Update the register mode with the latest change so that if the user
		// switches immediately back to the register the mode reflects the change
		if($success == TRUE)
		{
			if(config('OSPOS')->invoice_enable)
			{
				$this->sale_lib->set_mode($batch_save_data['default_register_mode']);
			}
			else
			{
				$this->sale_lib->set_mode('sale');
			}
		}

		echo json_encode (['success' => $success, 'message' => lang('Config.saved_' . ($success ? '' : 'un') . 'successfully')]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function remove_logo(): void
	{
		$success = $this->appconfig->save(['company_logo' => '']);

		echo json_encode (['success' => $success]);
	}

	private function _handle_logo_upload(): bool    //TODO: Remove hungarian notation.
	{
		helper('directory');

		// load upload library
		$config = ['upload_path' => './uploads/',
			'allowed_types' => 'gif|jpg|png',
			'max_size' => '1024',
			'max_width' => '800',
			'max_height' => '680',
			'file_name' => 'company_logo'
		];
		$this->upload = new Upload($config);	//TODO: Need to find the CI4 equivalent to the upload class.
		$this->upload->do_upload('company_logo');

		return strlen($this->upload->display_errors()) == 0 || !strcmp($this->upload->display_errors(), '<p>'.lang('upload_no_file_selected').'</p>');	//TODO: this should probably be broken up into
	}

	/**
	 * @throws ReflectionException
	 */
	private function _check_encryption(): bool        //TODO: Hungarian notation
	{
		$encryption_key = config('OSPOS')->encryption_key;

		// check if the encryption_key config item is the default one
		if($encryption_key == '' || $encryption_key == 'YOUR KEY')
		{
			// Config path
			$config_path = APPPATH . 'config/config.php';

			// Open the file
			$config = file_get_contents($config_path);

			// $key will be assigned a 32-byte (256-bit) hex-encoded random key
			$key = bin2hex($this->encryption->createKey(32));

			// set the encryption key in the config item
			$this->appconfig->save(['encryption_key' => $key]);

			// replace the empty placeholder with a real randomly generated encryption key
			$config = preg_replace("/(.*encryption_key.*)('');/", "$1'$key';", $config);

			$result = FALSE;

			// Chmod the file
			@chmod($config_path, 0770);

			// Verify file permissions
			if(is_writable($config_path))
			{
				// Write the new config.php file
				$handle = @fopen($config_path, 'w+');

				// Write the file
				$result = (fwrite($handle, $config) === FALSE) ? FALSE : TRUE;	//TODO: This can be replaced with `(fwrite($handle, $config) === FALSE);`

				fclose($handle);
			}

			// Chmod the file
			@chmod($config_path, 0440);

			return $result;
		}

		return TRUE;
	}

	public function backup_db(): void
	{
		$employee_id = $this->employee->get_logged_in_employee_info()->person_id;
		if($this->employee->has_module_grant('config', $employee_id))
		{
			$this->load->dbutil();	//TODO: CI4 does not have this utility any longer https://forum.codeigniter.com/thread-78658-post-384595.html#pid384595

			$prefs = ['format' => 'zip', 'filename' => 'ospos.sql'];

			$backup = $this->dbutil->backup($prefs);	//TODO: We need a new method for backing up the database.

			$file_name = 'ospos-' . date("Y-m-d-H-i-s") .'.zip';
			$save = 'uploads/' . $file_name;
			helper('download');
			while(ob_get_level())
			{
				ob_end_clean();
			}

			force_download($file_name, $backup);
		}
		else
		{
			redirect('no_access/config');
		}
	}
}
?>
