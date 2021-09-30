<?php

namespace App\Controllers;

use app\Libraries\Barcode_lib;
use app\Libraries\Email_lib;
use app\Libraries\Sale_lib;
use app\Libraries\Tax_lib;
use app\Libraries\Token_lib;

use app\Models\Appconfig;
use app\Models\Customer;
use app\Models\Customer_rewards;
use app\Models\Dinner_table;
use app\Models\Giftcard;
use app\Models\Inventory;
use app\Models\Item;
use app\Models\Item_kit;
use app\Models\Sale;
use app\Models\Stock_location;

use app\Models\Tokens\Token_invoice_count;
use app\Models\Tokens\Token_customer;
use app\Models\Tokens\Token_invoice_sequence;

/**
 * 
 *
 * @property barcode_lib barcode_lib
 * @property email_lib email_lib
 * @property sale_lib sale_lib
 * @property tax_lib tax_lib
 * @property token_lib token_lib
 * 
 * @property appconfig appconfig
 * @property customer customer
 * @property customer_rewards customer_rewards
 * @property dinner_table dinner_table
 * @property giftcard giftcard
 * @property inventory inventory
 * @property item item
 * @property item_kit item_kit
 * @property sale sale
 * @property stock_location stock_location
 * 
 */
class Sales extends Secure_Controller
{
	public function __construct()
	{
		parent::__construct('sales');

		helper('file');

		$this->barcode_lib = new Barcode_lib();
		$this->email_lib = new Email_lib();
		$this->sale_lib = new Sale_lib();
		$this->tax_lib = new Tax_lib();
		$this->token_lib = new Token_lib();

		$this->appconfig = model('Appconfig');
		$this->customer = model('Customer');
		$this->customer_rewards = model('Customer_rewards');
		$this->dinner_table = model('Dinner_table');
		$this->giftcard = model('Giftcard');
		$this->inventory = model('Inventory');
		$this->item = model('Item');
		$this->item_kit = model('Item_kit');
		$this->sale = model('Sale');
		$this->stock_location = model('Stock_location');
	}

	public function index()
	{
		$this->session->set_userdata('allow_temp_items', 1);
		$this->_reload();	//TODO: Hungarian Notation
	}

	public function manage()
	{
		$person_id = $this->session->userdata('person_id');

		if(!$this->Employee->has_grant('reports_sales', $person_id))
		{
			redirect('no_access/sales/reports_sales');
		}
		else
		{
			$data['table_headers'] = get_sales_manage_table_headers();

			$data['filters'] = ['only_cash' => lang('Sales.cash_filter'),
				'only_due' => lang('Sales.due_filter'),
				'only_check' => lang('Sales.check_filter'),
				'only_creditcard' => lang('Sales.credit_filter'),
				'only_invoices' => lang('Sales.invoice_filter')
			];

			echo view('sales/manage', $data);
		}
	}

	public function get_row(int $row_id)
	{
		$sale_info = $this->sale->get_info($row_id)->getRow();
		$data_row = $this->xss_clean(get_sale_data_row($sale_info));

		echo json_encode($data_row);
	}

	public function search()
	{
		$search = $this->request->getGet('search');
		$limit = $this->request->getGet('limit');
		$offset = $this->request->getGet('offset');
		$sort = $this->request->getGet('sort');
		$order = $this->request->getGet('order');

		$filters = [
			'sale_type' => 'all',
			'location_id' => 'all',
			'start_date' => $this->request->getGet('start_date'),
			'end_date' => $this->request->getGet('end_date'),
			'only_cash' => FALSE,
			'only_due' => FALSE,
			'only_check' => FALSE,
			'only_creditcard' => FALSE,
			'only_invoices' => $this->appconfig->get('invoice_enable') && $this->request->getGet('only_invoices'),
			'is_valid_receipt' => $this->sale->is_valid_receipt($search)
		];

		// check if any filter is set in the multiselect dropdown
		$filledup = array_fill_keys($this->request->getGet('filters'), TRUE);	//TODO: Variable does not meet naming conventions
		$filters = array_merge($filters, $filledup);

		$sales = $this->sale->search($search, $filters, $limit, $offset, $sort, $order);
		$total_rows = $this->sale->get_found_rows($search, $filters);
		$payments = $this->sale->get_payments_summary($search, $filters);
		$payment_summary = $this->xss_clean(get_sales_manage_payments_summary($payments));

		$data_rows = [];
		foreach($sales->getResult() as $sale)
		{
			$data_rows[] = $this->xss_clean(get_sale_data_row($sale));
		}

		if($total_rows > 0)
		{
			$data_rows[] = $this->xss_clean(get_sale_data_last_row($sales));
		}

		echo json_encode (['total' => $total_rows, 'rows' => $data_rows, 'payment_summary' => $payment_summary]);
	}

	public function item_search()
	{
		$suggestions = [];
		$receipt = $search = $this->request->getGet('term') != '' ? $this->request->getGet('term') : NULL;

		if($this->sale_lib->get_mode() == 'return' && $this->sale->is_valid_receipt($receipt))
		{
			// if a valid receipt or invoice was found the search term will be replaced with a receipt number (POS #)
			$suggestions[] = $receipt;
		}
		$suggestions = array_merge($suggestions, $this->item->get_search_suggestions($search, ['search_custom' => FALSE, 'is_deleted' => FALSE], TRUE));
		$suggestions = array_merge($suggestions, $this->item_kit->get_search_suggestions($search));

		$suggestions = $this->xss_clean($suggestions);

		echo json_encode($suggestions);
	}

	public function suggest_search()
	{
		$search = $this->request->getPost('term') != '' ? $this->request->getPost('term') : NULL;

		$suggestions = $this->xss_clean($this->sale->get_search_suggestions($search));

		echo json_encode($suggestions);
	}

	public function select_customer()
	{
		$customer_id = $this->request->getPost('customer');
		if($this->customer->exists($customer_id))
		{
			$this->sale_lib->set_customer($customer_id);
			$discount = $this->customer->get_info($customer_id)->discount;
			$discount_type = $this->customer->get_info($customer_id)->discount_type;

			// apply customer default discount to items that have 0 discount
			if($discount != '')
			{
				$this->sale_lib->apply_customer_discount($discount, $discount_type);
			}
		}

		$this->_reload();
	}

	public function change_mode()
	{
		$mode = $this->request->getPost('mode');
		$this->sale_lib->set_mode($mode);

		if($mode == 'sale')
		{
			$this->sale_lib->set_sale_type(SALE_TYPE_POS);
		}
		else if($mode == 'sale_quote')
		{
			$this->sale_lib->set_sale_type(SALE_TYPE_QUOTE);
		}
		else if($mode == 'sale_work_order')
		{
			$this->sale_lib->set_sale_type(SALE_TYPE_WORK_ORDER);
		}
		else if($mode == 'sale_invoice')
		{
			$this->sale_lib->set_sale_type(SALE_TYPE_INVOICE);
		}
		else
		{
			$this->sale_lib->set_sale_type(SALE_TYPE_RETURN);
		}

		if($this->appconfig->get('dinner_table_enable') == TRUE)
		{
			$occupied_dinner_table = $this->request->getPost('dinner_table');
			$released_dinner_table = $this->sale_lib->get_dinner_table();
			$occupied = $this->dinner_table->is_occupied($released_dinner_table);

			if($occupied && ($occupied_dinner_table != $released_dinner_table))
			{
				$this->dinner_table->swap_tables($released_dinner_table, $occupied_dinner_table);
			}

			$this->sale_lib->set_dinner_table($occupied_dinner_table);
		}

		$stock_location = $this->request->getPost('stock_location');
		if(!$stock_location || $stock_location == $this->sale_lib->get_sale_location())
		{
//TODO: This has an empty body.  What to do here?
		}
		elseif($this->stock_location->is_allowed_location($stock_location, 'sales'))
		{
			$this->sale_lib->set_sale_location($stock_location);
		}

		$this->sale_lib->empty_payments();

		$this->_reload();
	}

	public function change_register_mode(int $sale_type)
	{//TODO: This set of if statements should be refactored to a switch
		if($sale_type == SALE_TYPE_POS)
		{
			$this->sale_lib->set_mode('sale');
		}
		elseif($sale_type == SALE_TYPE_QUOTE)
		{
			$this->sale_lib->set_mode('sale_quote');
		}
		elseif($sale_type == SALE_TYPE_WORK_ORDER)
		{
			$this->sale_lib->set_mode('sale_work_order');
		}
		elseif($sale_type == SALE_TYPE_INVOICE)
		{
			$this->sale_lib->set_mode('sale_invoice');
		}
		elseif($sale_type == SALE_TYPE_RETURN)
		{
			$this->sale_lib->set_mode('return');
		}
		else
		{
			$this->sale_lib->set_mode('sale');
		}
	}

	public function set_comment()
	{
		$this->sale_lib->set_comment($this->request->getPost('comment'));
	}

	public function set_invoice_number()
	{
		$this->sale_lib->set_invoice_number($this->request->getPost('sales_invoice_number'));
	}

	public function set_payment_type()
	{
		$this->sale_lib->set_payment_type($this->request->getPost('selected_payment_type'));
		$this->_reload();	//TODO: Hungarian notation.
	}

	public function set_print_after_sale()
	{
		$this->sale_lib->set_print_after_sale($this->request->getPost('sales_print_after_sale'));
	}

	public function set_price_work_orders()
	{
		$this->sale_lib->set_price_work_orders($this->request->getPost('price_work_orders'));
	}

	public function set_email_receipt()
	{
		$this->sale_lib->set_email_receipt($this->request->getPost('email_receipt'));
	}

	// Multiple Payments
	public function add_payment()
	{
		$data = [];

		$payment_type = $this->request->getPost('payment_type');

		//TODO: See the code block below.  This too needs to be ternary notation.
		if($payment_type !== lang('Sales.giftcard'))
		{
			$this->form_validation->set_rules('amount_tendered', 'lang:sales_amount_tendered', 'trim|required|callback_numeric');	//TODO: Form validation needs to be reworked to be CI4 compatible.
		}
		else
		{
			$this->form_validation->set_rules('amount_tendered', 'lang:sales_amount_tendered', 'trim|required');
		}

		if($this->form_validation->run() == FALSE)
		{//TODO: the code below should be refactored to the following since it's much more readable and concise:
			//$data['error'] = $payment_type === lang('Sales.giftcard')
			//	? $data['error'] = lang('Sales.must_enter_numeric_giftcard')
			//	: $data['error'] = lang('Sales.must_enter_numeric');

			if($payment_type === lang('Sales.giftcard'))
			{
				$data['error'] = lang('Sales.must_enter_numeric_giftcard');
			}
			else
			{
				$data['error'] = lang('Sales.must_enter_numeric');
			}
		}
		else
		{
			if($payment_type === lang('Sales.giftcard'))
			{
				// in case of giftcard payment the register input amount_tendered becomes the giftcard number
				$giftcard_num = $this->request->getPost('amount_tendered');

				$payments = $this->sale_lib->get_payments();
				$payment_type = $payment_type . ':' . $giftcard_num;
				$current_payments_with_giftcard = isset($payments[$payment_type]) ? $payments[$payment_type]['payment_amount'] : 0;
				$cur_giftcard_value = $this->giftcard->get_giftcard_value($giftcard_num);
				$cur_giftcard_customer = $this->giftcard->get_giftcard_customer($giftcard_num);
				$customer_id = $this->sale_lib->get_customer();

				if(isset($cur_giftcard_customer) && $cur_giftcard_customer != $customer_id)
				{
					$data['error'] = lang('Giftcards.cannot_use', $giftcard_num);
				}
				elseif(($cur_giftcard_value - $current_payments_with_giftcard) <= 0 && $this->sale_lib->get_mode() == 'sale')
				{
					$data['error'] = lang('Giftcards.remaining_balance', $giftcard_num, to_currency($cur_giftcard_value));
				}
				else
				{
					$new_giftcard_value = $this->giftcard->get_giftcard_value($giftcard_num) - $this->sale_lib->get_amount_due();
					$new_giftcard_value = $new_giftcard_value >= 0 ? $new_giftcard_value : 0;
					$this->sale_lib->set_giftcard_remainder($new_giftcard_value);
					$new_giftcard_value = str_replace('$', '\$', to_currency($new_giftcard_value));
					$data['warning'] = lang('Giftcards.remaining_balance', $giftcard_num, $new_giftcard_value);
					$amount_tendered = min($this->sale_lib->get_amount_due(), $this->giftcard->get_giftcard_value($giftcard_num));

					$this->sale_lib->add_payment($payment_type, $amount_tendered);
				}
			}
			elseif($payment_type === lang('Sales.rewards'))
			{
				$customer_id = $this->sale_lib->get_customer();
				$package_id = $this->customer->get_info($customer_id)->package_id;
				if(!empty($package_id))
				{
					$package_name = $this->customer_rewards->get_name($package_id);
					$points = $this->customer->get_info($customer_id)->points;
					$points = ($points == NULL ? 0 : $points);

					$payments = $this->sale_lib->get_payments();
					$payment_type = $payment_type;	//TODO: hmmmm.  Assigning the variable to itself.  I'm not sure this was intended.
					$current_payments_with_rewards = isset($payments[$payment_type]) ? $payments[$payment_type]['payment_amount'] : 0;
					$cur_rewards_value = $points;

					if(($cur_rewards_value - $current_payments_with_rewards) <= 0)
					{
						$data['error'] = lang('Sales.rewards_remaining_balance') . to_currency($cur_rewards_value);
					}
					else
					{
						$new_reward_value = $points - $this->sale_lib->get_amount_due();
						$new_reward_value = $new_reward_value >= 0 ? $new_reward_value : 0;
						$this->sale_lib->set_rewards_remainder($new_reward_value);
						$new_reward_value = str_replace('$', '\$', to_currency($new_reward_value));
						$data['warning'] = lang('Sales.rewards_remaining_balance'). $new_reward_value;
						$amount_tendered = min($this->sale_lib->get_amount_due(), $points);

						$this->sale_lib->add_payment($payment_type, $amount_tendered);
					}
				}
			}
			elseif($payment_type === lang('Sales.cash'))
			{
				$amount_due = $this->sale_lib->get_total();
				$sales_total = $this->sale_lib->get_total(FALSE);

				$amount_tendered = $this->request->getPost('amount_tendered');
				$this->sale_lib->add_payment($payment_type, $amount_tendered);
				$cash_adjustment_amount = $amount_due - $sales_total;
				if($cash_adjustment_amount <> 0)
				{
					$this->session->set_userdata('cash_mode', CASH_MODE_TRUE);
					$this->sale_lib->add_payment(lang('Sales.cash_adjustment'), $cash_adjustment_amount, CASH_ADJUSTMENT_TRUE);
				}
			}
			else
			{
				$amount_tendered = $this->request->getPost('amount_tendered');
				$this->sale_lib->add_payment($payment_type, $amount_tendered);
			}
		}

		$this->_reload($data);	//TODO: Hungarian notation
	}

	// Multiple Payments
	public function delete_payment(string $payment_id)
	{
		$this->sale_lib->delete_payment($payment_id);

		$this->_reload();	//TODO: Hungarian notation
	}

	public function add()
	{
		$data = [];

		$discount = $this->appconfig->get('default_sales_discount');
		$discount_type = $this->appconfig->get('default_sales_discount_type');

		// check if any discount is assigned to the selected customer
		$customer_id = $this->sale_lib->get_customer();
		if($customer_id != -1)	//TODO: Replace -1 with a constant
		{
			// load the customer discount if any
			$customer_discount = $this->customer->get_info($customer_id)->discount;
			$customer_discount_type = $this->customer->get_info($customer_id)->discount_type;
			if($customer_discount != '')
			{
				$discount = $customer_discount;
				$discount_type = $customer_discount_type;
			}
		}

		$item_id_or_number_or_item_kit_or_receipt = $this->request->getPost('item');
		$this->token_lib->parse_barcode($quantity, $price, $item_id_or_number_or_item_kit_or_receipt);
		$mode = $this->sale_lib->get_mode();
		$quantity = ($mode == 'return') ? -$quantity : $quantity;
		$item_location = $this->sale_lib->get_sale_location();

		if($mode == 'return' && $this->sale->is_valid_receipt($item_id_or_number_or_item_kit_or_receipt))
		{
			$this->sale_lib->return_entire_sale($item_id_or_number_or_item_kit_or_receipt);
		}
		elseif($this->item_kit->is_valid_item_kit($item_id_or_number_or_item_kit_or_receipt))
		{
			// Add kit item to order if one is assigned
			$pieces = explode(' ', $item_id_or_number_or_item_kit_or_receipt);

			$item_kit_id = (count($pieces) > 1) ? $pieces[1] : $item_id_or_number_or_item_kit_or_receipt;
			$item_kit_info = $this->item_kit->get_info($item_kit_id);
			$kit_item_id = $item_kit_info->kit_item_id;
			$kit_price_option = $item_kit_info->price_option;
			$kit_print_option = $item_kit_info->print_option; // 0-all, 1-priced, 2-kit-only

			if($discount_type == $item_kit_info->kit_discount_type)
			{
				if($item_kit_info->kit_discount > $discount)
				{
					$discount = $item_kit_info->kit_discount;
				}
			}
			else
			{
				$discount = $item_kit_info->kit_discount;
				$discount_type = $item_kit_info->kit_discount_type;
			}

			$print_option = PRINT_ALL; // Always include in list of items on invoice

			if(!empty($kit_item_id))
			{
				if(!$this->sale_lib->add_item($kit_item_id, $quantity, $item_location, $discount, $discount_type, PRICE_MODE_KIT, $kit_price_option, $kit_print_option, $price))
				{
					$data['error'] = lang('Sales.unable_to_add_item');
				}
				else
				{
					$data['warning'] = $this->sale_lib->out_of_stock($item_kit_id, $item_location);
				}
			}

			// Add item kit items to order
			$stock_warning = NULL;
			if(!$this->sale_lib->add_item_kit($item_id_or_number_or_item_kit_or_receipt, $item_location, $discount, $discount_type, $kit_price_option, $kit_print_option, $stock_warning))
			{
				$data['error'] = lang('Sales.unable_to_add_item');
			}
			elseif($stock_warning != NULL)
			{
				$data['warning'] = $stock_warning;
			}
		}
		else
		{
			if(!$this->sale_lib->add_item($item_id_or_number_or_item_kit_or_receipt, $quantity, $item_location, $discount, $discount_type, PRICE_MODE_STANDARD, NULL, NULL, $price))
			{
				$data['error'] = lang('Sales.unable_to_add_item');
			}
			else
			{
				$data['warning'] = $this->sale_lib->out_of_stock($item_id_or_number_or_item_kit_or_receipt, $item_location);
			}
		}

		$this->_reload($data);
	}

	public function edit_item(int $item_id)
	{
		$data = [];

		$this->form_validation->set_rules('price', 'lang:sales_price', 'required|callback_numeric');	//TODO: Form Validation
		$this->form_validation->set_rules('quantity', 'lang:sales_quantity', 'required|callback_numeric');
		$this->form_validation->set_rules('discount', 'lang:sales_discount', 'required|callback_numeric');

		$description = $this->request->getPost('description');
		$serialnumber = $this->request->getPost('serialnumber');
		$price = parse_decimals($this->request->getPost('price'));
		$quantity = parse_quantity($this->request->getPost('quantity'));
		$discount_type = $this->request->getPost('discount_type');
		$discount = $discount_type ? parse_quantity($this->request->getPost('discount')) : parse_decimals($this->request->getPost('discount'));

		$item_location = $this->request->getPost('location');
		$discounted_total = $this->request->getPost('discounted_total') != '' ? $this->request->getPost('discounted_total') : NULL;

		if($this->form_validation->run() != FALSE)
		{
			$this->sale_lib->edit_item($item_id, $description, $serialnumber, $quantity, $discount, $discount_type, $price, $discounted_total);
			
			$this->sale_lib->empty_payments();
		}
		else
		{
			$data['error'] = lang('Sales.error_editing_item');
		}

		$data['warning'] = $this->sale_lib->out_of_stock($this->sale_lib->get_item_id($item_id), $item_location);

		$this->_reload($data);	//TODO: Hungarian notation
	}

	public function delete_item(string $item_number)
	{
		$this->sale_lib->delete_item($item_number);

		$this->sale_lib->empty_payments();		

		$this->_reload();	//TODO: Hungarian notation
	}

	public function remove_customer()
	{
		$this->sale_lib->clear_giftcard_remainder();
		$this->sale_lib->clear_rewards_remainder();
		$this->sale_lib->delete_payment(lang('Sales.rewards'));
		$this->sale_lib->clear_invoice_number();
		$this->sale_lib->clear_quote_number();
		$this->sale_lib->remove_customer();

		$this->_reload();	//TODO: Hungarian notation
	}

	public function complete()	//TODO: this function is huge.  Probably should be refactored.
	{
		$sale_id = $this->sale_lib->get_sale_id();
		$sale_type = $this->sale_lib->get_sale_type();	//TODO: This variable gets overwritten way down below.
		$data = [];
		$data['dinner_table'] = $this->sale_lib->get_dinner_table();

		$data['cart'] = $this->sale_lib->get_cart();

		$data['include_hsn'] = ($this->appconfig->get('include_hsn') == '1');
		$__time = time();
		$data['transaction_time'] = to_datetime($__time);
		$data['transaction_date'] = to_date($__time);
		$data['show_stock_locations'] = $this->stock_location->show_locations('sales');
		$data['comments'] = $this->sale_lib->get_comment();
		$employee_id = $this->Employee->get_logged_in_employee_info()->person_id;
		$employee_info = $this->Employee->get_info($employee_id);
		$data['employee'] = $employee_info->first_name . ' ' . mb_substr($employee_info->last_name, 0, 1);

		$data['company_info'] = implode("\n", [$this->appconfig->get('address'), $this->appconfig->get('phone')]);

		if($this->appconfig->get('account_number'))
		{
			$data['company_info'] .= "\n" . lang('Sales.account_number') . ": " . $this->appconfig->get('account_number');
		}

		if($this->appconfig->get('tax_id') != '')
		{
			$data['company_info'] .= "\n" . lang('Sales.tax_id') . ": " . $this->appconfig->get('tax_id');
		}

		$data['invoice_number_enabled'] = $this->sale_lib->is_invoice_mode();
		$data['cur_giftcard_value'] = $this->sale_lib->get_giftcard_remainder();
		$data['cur_rewards_value'] = $this->sale_lib->get_rewards_remainder();
		$data['print_after_sale'] = $this->sale_lib->is_print_after_sale();
		$data['price_work_orders'] = $this->sale_lib->is_price_work_orders();
		$data['email_receipt'] = $this->sale_lib->is_email_receipt();
		$customer_id = $this->sale_lib->get_customer();
		$invoice_number = $this->sale_lib->get_invoice_number();
		$data["invoice_number"] = $invoice_number;
		$work_order_number = $this->sale_lib->get_work_order_number();
		$data["work_order_number"] = $work_order_number;
		$quote_number = $this->sale_lib->get_quote_number();
		$data["quote_number"] = $quote_number;
		$customer_info = $this->_load_customer_data($customer_id, $data);

		if($customer_info != NULL)
		{
			$data["customer_comments"] = $customer_info->comments;
			$data['tax_id'] = $customer_info->tax_id;
		}
		$tax_details = $this->tax_lib->get_taxes($data['cart']);	//TODO: Duplicated code
		$data['taxes'] = $tax_details[0];
		$data['discount'] = $this->sale_lib->get_discount();
		$data['payments'] = $this->sale_lib->get_payments();

		// Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
		$totals = $this->sale_lib->get_totals($tax_details[0]);
		$data['subtotal'] = $totals['subtotal'];
		$data['total'] = $totals['total'];
		$data['payments_total'] = $totals['payment_total'];
		$data['payments_cover_total'] = $totals['payments_cover_total'];
		$data['cash_rounding'] = $this->session->userdata('cash_rounding');
		$data['cash_mode'] = $this->session->userdata('cash_mode');	//TODO: Duplicated code
		$data['prediscount_subtotal'] = $totals['prediscount_subtotal'];
		$data['cash_total'] = $totals['cash_total'];
		$data['non_cash_total'] = $totals['total'];
		$data['cash_amount_due'] = $totals['cash_amount_due'];
		$data['non_cash_amount_due'] = $totals['amount_due'];

		if($data['cash_mode'])	//TODO: Convert this to ternary notation
		{
			$data['amount_due'] = $totals['cash_amount_due'];
		}
		else
		{
			$data['amount_due'] = $totals['amount_due'];
		}

		$data['amount_change'] = $data['amount_due'] * -1;

		if($data['amount_change'] > 0)
		{
			// Save cash refund to the cash payment transaction if found, if not then add as new Cash transaction

			if(array_key_exists(lang('Sales.cash'), $data['payments']))
			{
				$data['payments'][lang('Sales.cash')]['cash_refund'] = $data['amount_change'];
			}
			else
			{
				$payment = [
					lang('Sales.cash') => [
						'payment_type' => lang('Sales.cash'),
						'payment_amount' => 0,
						'cash_refund' => $data['amount_change']
					]
				];

				$data['payments'] += $payment;
			}
		}

		$data['print_price_info'] = TRUE;

		if($this->sale_lib->is_invoice_mode())
		{
			$invoice_format = $this->appconfig->get('sales_invoice_format');

			// generate final invoice number (if using the invoice in sales by receipt mode then the invoice number can be manually entered or altered in some way
			if(!empty($invoice_format) && $invoice_number == NULL)
			{
				// The user can retain the default encoded format or can manually override it.  It still passes through the rendering step.
				$invoice_number = $this->token_lib->render($invoice_format);
			}


			if($sale_id == -1 && $this->sale->check_invoice_number_exists($invoice_number))
			{
				$data['error'] = lang('Sales.invoice_number_duplicate', $invoice_number);
				$this->_reload($data);
			}
			else
			{
				$data['invoice_number'] = $invoice_number;
				$data['sale_status'] = COMPLETED;
				$sale_type = SALE_TYPE_INVOICE;

				// The PHP file name is the same as the invoice_type key
				$invoice_view = $this->appconfig->get('invoice_type');

				// Save the data to the sales table
				$data['sale_id_num'] = $this->sale->save($sale_id, $data['sale_status'], $data['cart'], $customer_id, $employee_id, $data['comments'], $invoice_number, $work_order_number, $quote_number, $sale_type, $data['payments'], $data['dinner_table'], $tax_details);
				$data['sale_id'] = 'POS ' . $data['sale_id_num'];

				// Resort and filter cart lines for printing
				$data['cart'] = $this->sale_lib->sort_and_filter_cart($data['cart']);

				$data = $this->xss_clean($data);

				if($data['sale_id_num'] == -1)
				{
					$data['error_message'] = lang('Sales.transaction_failed');
				}
				else
				{
					$data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
					echo view('sales/'.$invoice_view, $data);
					$this->sale_lib->clear_all();
				}
			}
		}
		elseif($this->sale_lib->is_work_order_mode())
		{

			if(!($data['price_work_orders'] == 1))
			{
				$data['print_price_info'] = FALSE;
			}

			$data['sales_work_order'] = lang('Sales.work_order');
			$data['work_order_number_label'] = lang('Sales.work_order_number');

			if($work_order_number == NULL)
			{
				// generate work order number
				$work_order_format = $this->appconfig->get('work_order_format');
				$work_order_number = $this->token_lib->render($work_order_format);
			}

			if($sale_id == -1 && $this->sale->check_work_order_number_exists($work_order_number))
			{
				$data['error'] = lang('Sales.work_order_number_duplicate');
				$this->_reload($data);
			}
			else
			{
				$data['work_order_number'] = $work_order_number;
				$data['sale_status'] = SUSPENDED;
				$sale_type = SALE_TYPE_WORK_ORDER;

				$data['sale_id_num'] = $this->sale->save($sale_id, $data['sale_status'], $data['cart'], $customer_id, $employee_id, $data['comments'], $invoice_number, $work_order_number, $quote_number, $sale_type, $data['payments'], $data['dinner_table'], $tax_details);
				$this->sale_lib->set_suspended_id($data['sale_id_num']);

				$data['cart'] = $this->sale_lib->sort_and_filter_cart($data['cart']);

				$data = $this->xss_clean($data);

				$data['barcode'] = NULL;

				echo view('sales/work_order', $data);
				$this->sale_lib->clear_mode();
				$this->sale_lib->clear_all();
			}
		}
		elseif($this->sale_lib->is_quote_mode())
		{
			$data['sales_quote'] = lang('Sales.quote');
			$data['quote_number_label'] = lang('Sales.quote_number');

			if($quote_number == NULL)
			{
				// generate quote number
				$quote_format = $this->appconfig->get('sales_quote_format');
				$quote_number = $this->token_lib->render($quote_format);
			}

			if($sale_id == -1 && $this->sale->check_quote_number_exists($quote_number))
			{
				$data['error'] = lang('Sales.quote_number_duplicate');
				$this->_reload($data);
			}
			else
			{
				$data['quote_number'] = $quote_number;
				$data['sale_status'] = SUSPENDED;
				$sale_type = SALE_TYPE_QUOTE;

				$data['sale_id_num'] = $this->sale->save($sale_id, $data['sale_status'], $data['cart'], $customer_id, $employee_id, $data['comments'], $invoice_number, $work_order_number, $quote_number, $sale_type, $data['payments'], $data['dinner_table'], $tax_details);
				$this->sale_lib->set_suspended_id($data['sale_id_num']);

				$data['cart'] = $this->sale_lib->sort_and_filter_cart($data['cart']);

				$data = $this->xss_clean($data);

				$data['barcode'] = NULL;

				echo view('sales/quote', $data);
				$this->sale_lib->clear_mode();
				$this->sale_lib->clear_all();
			}
		}
		else
		{
			// Save the data to the sales table
			$data['sale_status'] = COMPLETED;
			if($this->sale_lib->is_return_mode())
			{
				$sale_type = SALE_TYPE_RETURN;
			}
			else
			{
				$sale_type = SALE_TYPE_POS;
			}

			$data['sale_id_num'] = $this->sale->save($sale_id, $data['sale_status'], $data['cart'], $customer_id, $employee_id, $data['comments'], $invoice_number, $work_order_number, $quote_number, $sale_type, $data['payments'], $data['dinner_table'], $tax_details);

			$data['sale_id'] = 'POS ' . $data['sale_id_num'];

			$data['cart'] = $this->sale_lib->sort_and_filter_cart($data['cart']);
			$data = $this->xss_clean($data);

			if($data['sale_id_num'] == -1)	//TODO: Replace -1 with a constant
			{
				$data['error_message'] = lang('Sales.transaction_failed');
			}
			else
			{
				$data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
				echo view('sales/receipt', $data);
				$this->sale_lib->clear_all();
			}
		}
	}

	public function send_pdf(int $sale_id, string $type = 'invoice'): bool
	{
		$sale_data = $this->_load_sale_data($sale_id);

		$result = FALSE;
		$message = lang('Sales.invoice_no_email');

		if(!empty($sale_data['customer_email']))
		{
			$to = $sale_data['customer_email'];
			$number = $sale_data[$type."_number"];
			$subject = lang('Sales.' . $type) . ' ' . $number;

			$text = $this->appconfig->get('invoice_email_message');
			$tokens = [
				new Token_invoice_sequence($sale_data['invoice_number']),
				new Token_invoice_count('POS ' . $sale_data['sale_id']),
				new Token_customer((object)$sale_data)
			];
			$text = $this->token_lib->render($text, $tokens);
			$sale_data['mimetype'] = get_mime_by_extension('uploads/' . $this->appconfig->get('company_logo'));	//TODO: Need to replace get_mime_by_extension

			// generate email attachment: invoice in pdf format
			$html = view("sales/" . $type . "_email", $sale_data, TRUE);	//TODO: view is expecting the last param to be an array

			// load pdf helper
			helper (['dompdf', 'file']);
			$filename = sys_get_temp_dir() . '/' . lang('Sales.' . $type) . '-' . str_replace('/', '-', $number) . '.pdf';
			if(file_put_contents($filename, create_pdf($html)) !== FALSE)
			{
				$result = $this->email_lib->sendEmail($to, $subject, $text, $filename);
			}

			$message = lang($result ? "sales_" . $type . "_sent" : "sales_" . $type . "_unsent") . ' ' . $to;
		}

		echo json_encode (['success' => $result, 'message' => $message, 'id' => $sale_id]);

		$this->sale_lib->clear_all();

		return $result;
	}

	public function send_receipt(int $sale_id): bool
	{
		$sale_data = $this->_load_sale_data($sale_id);

		$result = FALSE;
		$message = lang('Sales.receipt_no_email');

		if(!empty($sale_data['customer_email']))
		{
			$sale_data['barcode'] = $this->barcode_lib->generate_receipt_barcode($sale_data['sale_id']);

			$to = $sale_data['customer_email'];
			$subject = lang('Sales.receipt');

			$text = view('sales/receipt_email', $sale_data, TRUE);	//TODO: view is expecting the last param to be an array

			$result = $this->email_lib->sendEmail($to, $subject, $text);

			$message = lang($result ? 'sales_receipt_sent' : 'sales_receipt_unsent') . ' ' . $to;
		}

		echo json_encode (['success' => $result, 'message' => $message, 'id' => $sale_id]);

		$this->sale_lib->clear_all();

		return $result;
	}

	private function _load_customer_data(int $customer_id, array &$data, bool $stats = FALSE)	//TODO: Hungarian notation
	{
		$customer_info = '';

		if($customer_id != -1)
		{
			$customer_info = $this->customer->get_info($customer_id);
			$data['customer_id'] = $customer_id;

			if(!empty($customer_info->company_name))
			{
				$data['customer'] = $customer_info->company_name;
			}
			else
			{
				$data['customer'] = $customer_info->first_name . ' ' . $customer_info->last_name;
			}

			$data['first_name'] = $customer_info->first_name;
			$data['last_name'] = $customer_info->last_name;
			$data['customer_email'] = $customer_info->email;
			$data['customer_address'] = $customer_info->address_1;

			if(!empty($customer_info->zip) || !empty($customer_info->city))
			{
				$data['customer_location'] = $customer_info->zip . ' ' . $customer_info->city . "\n" . $customer_info->state;
			}
			else
			{
				$data['customer_location'] = '';
			}

			$data['customer_account_number'] = $customer_info->account_number;
			$data['customer_discount'] = $customer_info->discount;
			$data['customer_discount_type'] = $customer_info->discount_type;
			$package_id = $this->customer->get_info($customer_id)->package_id;

			if($package_id != NULL)
			{
				$package_name = $this->customer_rewards->get_name($package_id);
				$points = $this->customer->get_info($customer_id)->points;
				$data['customer_rewards']['package_id'] = $package_id;
				$data['customer_rewards']['points'] = empty($points) ? 0 : $points;
				$data['customer_rewards']['package_name'] = $package_name;
			}

			if($stats)
			{
				$cust_stats = $this->customer->get_stats($customer_id);
				$data['customer_total'] = empty($cust_stats) ? 0 : $cust_stats->total;
			}

			$data['customer_info'] = implode("\n", [
				$data['customer'],
				$data['customer_address'],
				$data['customer_location']
			]);

			if($data['customer_account_number'])
			{
				$data['customer_info'] .= "\n" . lang('Sales.account_number') . ": " . $data['customer_account_number'];
			}

			if($customer_info->tax_id != '')
			{
				$data['customer_info'] .= "\n" . lang('Sales.tax_id') . ": " . $customer_info->tax_id;
			}
			$data['tax_id'] = $customer_info->tax_id;
		}

		return $customer_info;
	}

	private function _load_sale_data($sale_id)	//TODO: Hungarian notation
	{
		$this->sale_lib->clear_all();
		$cash_rounding = $this->sale_lib->reset_cash_rounding();
		$data['cash_rounding'] = $cash_rounding;

		$sale_info = $this->sale->get_info($sale_id)->getRowArray();
		$this->sale_lib->copy_entire_sale($sale_id);
		$data = [];
		$data['cart'] = $this->sale_lib->get_cart();
		$data['payments'] = $this->sale_lib->get_payments();
		$data['selected_payment_type'] = $this->sale_lib->get_payment_type();

		$tax_details = $this->tax_lib->get_taxes($data['cart'], $sale_id);
		$data['taxes'] = $this->sale->get_sales_taxes($sale_id);
		$data['discount'] = $this->sale_lib->get_discount();
		$data['transaction_time'] = to_datetime(strtotime($sale_info['sale_time']));
		$data['transaction_date'] = to_date(strtotime($sale_info['sale_time']));
		$data['show_stock_locations'] = $this->stock_location->show_locations('sales');

		$data['include_hsn'] = ($this->appconfig->get('include_hsn') == '1');

		// Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
		$totals = $this->sale_lib->get_totals($tax_details[0]);
		$this->session->set_userdata('cash_adjustment_amount', $totals['cash_adjustment_amount']);
		$data['subtotal'] = $totals['subtotal'];
		$data['payments_total'] = $totals['payment_total'];
		$data['payments_cover_total'] = $totals['payments_cover_total'];
		$data['cash_mode'] = $this->session->userdata('cash_mode');	//TODO: Duplicated code.
		$data['prediscount_subtotal'] = $totals['prediscount_subtotal'];
		$data['cash_total'] = $totals['cash_total'];
		$data['non_cash_total'] = $totals['total'];
		$data['cash_amount_due'] = $totals['cash_amount_due'];
		$data['non_cash_amount_due'] = $totals['amount_due'];

		if($data['cash_mode'] && ($data['selected_payment_type'] === lang('Sales.cash') || $data['payments_total'] > 0))
		{
			$data['total'] = $totals['cash_total'];
			$data['amount_due'] = $totals['cash_amount_due'];
		}
		else
		{
			$data['total'] = $totals['total'];
			$data['amount_due'] = $totals['amount_due'];
		}

		$data['amount_change'] = $data['amount_due'] * -1;

		$employee_info = $this->Employee->get_info($this->sale_lib->get_employee());
		$data['employee'] = $employee_info->first_name . ' ' . mb_substr($employee_info->last_name, 0, 1);
		$this->_load_customer_data($this->sale_lib->get_customer(), $data);

		$data['sale_id_num'] = $sale_id;
		$data['sale_id'] = 'POS ' . $sale_id;
		$data['comments'] = $sale_info['comment'];
		$data['invoice_number'] = $sale_info['invoice_number'];
		$data['quote_number'] = $sale_info['quote_number'];
		$data['sale_status'] = $sale_info['sale_status'];

		$data['company_info'] = implode("\n", [$this->appconfig->get('address'), $this->appconfig->get('phone')]);	//TODO: Duplicated code.

		if($this->appconfig->get('account_number'))
		{
			$data['company_info'] .= "\n" . lang('Sales.account_number') . ": " . $this->appconfig->get('account_number');
		}
		if($this->appconfig->get('tax_id') != '')
		{
			$data['company_info'] .= "\n" . lang('Sales.tax_id') . ": " . $this->appconfig->get('tax_id');
		}

		$data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
		$data['print_after_sale'] = FALSE;
		$data['price_work_orders'] = FALSE;

		if($this->sale_lib->get_mode() == 'sale_invoice')	//TODO: Duplicated code.
		{
			$data['mode_label'] = lang('Sales.invoice');
			$data['customer_required'] = lang('Sales.customer_required');
		}
		elseif($this->sale_lib->get_mode() == 'sale_quote')
		{
			$data['mode_label'] = lang('Sales.quote');
			$data['customer_required'] = lang('Sales.customer_required');
		}
		elseif($this->sale_lib->get_mode() == 'sale_work_order')
		{
			$data['mode_label'] = lang('Sales.work_order');
			$data['customer_required'] = lang('Sales.customer_required');
		}
		elseif($this->sale_lib->get_mode() == 'return')
		{
			$data['mode_label'] = lang('Sales.return');
			$data['customer_required'] = lang('Sales.customer_optional');
		}
		else
		{
			$data['mode_label'] = lang('Sales.receipt');
			$data['customer_required'] = lang('Sales.customer_optional');
		}

		$invoice_type = $this->appconfig->get('invoice_type');
		$data['invoice_view'] = $invoice_type;

		return $this->xss_clean($data);
	}

	private function _reload($data = [])	//TODO: Hungarian notation
	{
		$sale_id = $this->session->userdata('sale_id');

		if($sale_id == '')
		{
			$sale_id = -1;
			$this->session->set_userdata('sale_id', -1);
		}
		$cash_rounding = $this->sale_lib->reset_cash_rounding();

		// cash_rounding indicates only that the site is configured for cash rounding
		$data['cash_rounding'] = $cash_rounding;

		$data['cart'] = $this->sale_lib->get_cart();
		$customer_info = $this->_load_customer_data($this->sale_lib->get_customer(), $data, TRUE);

		$data['modes'] = $this->sale_lib->get_register_mode_options();
		$data['mode'] = $this->sale_lib->get_mode();
		$data['selected_table'] = $this->sale_lib->get_dinner_table();
		$data['empty_tables'] = $this->sale_lib->get_empty_tables($data['selected_table']);
		$data['stock_locations'] = $this->stock_location->get_allowed_locations('sales');
		$data['stock_location'] = $this->sale_lib->get_sale_location();
		$data['tax_exclusive_subtotal'] = $this->sale_lib->get_subtotal(TRUE, TRUE);
		$tax_details = $this->tax_lib->get_taxes($data['cart']);	//TODO: Duplicated code.
		$data['taxes'] = $tax_details[0];
		$data['discount'] = $this->sale_lib->get_discount();
		$data['payments'] = $this->sale_lib->get_payments();

		// Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
		$totals = $this->sale_lib->get_totals($tax_details[0]);

		$data['item_count'] = $totals['item_count'];
		$data['total_units'] = $totals['total_units'];
		$data['subtotal'] = $totals['subtotal'];
		$data['total'] = $totals['total'];
		$data['payments_total'] = $totals['payment_total'];
		$data['payments_cover_total'] = $totals['payments_cover_total'];

		// cash_mode indicates whether this sale is going to be processed using cash_rounding
		$cash_mode = $this->session->userdata('cash_mode');
		$data['cash_mode'] = $cash_mode;
		$data['prediscount_subtotal'] = $totals['prediscount_subtotal'];	//TODO: Duplicated code.
		$data['cash_total'] = $totals['cash_total'];
		$data['non_cash_total'] = $totals['total'];
		$data['cash_amount_due'] = $totals['cash_amount_due'];
		$data['non_cash_amount_due'] = $totals['amount_due'];

		$data['selected_payment_type'] = $this->sale_lib->get_payment_type();

		if($data['cash_mode'] && ($data['selected_payment_type'] == lang('Sales.cash') || $data['payments_total'] > 0))
		{
			$data['total'] = $totals['cash_total'];
			$data['amount_due'] = $totals['cash_amount_due'];
		}
		else
		{
			$data['total'] = $totals['total'];
			$data['amount_due'] = $totals['amount_due'];
		}

		$data['amount_change'] = $data['amount_due'] * -1;

		$data['comment'] = $this->sale_lib->get_comment();
		$data['email_receipt'] = $this->sale_lib->is_email_receipt();

		if($customer_info && $this->appconfig->get('customer_reward_enable') == TRUE)
		{
			$data['payment_options'] = $this->sale->get_payment_options(TRUE, TRUE);
		}
		else
		{
			$data['payment_options'] = $this->sale->get_payment_options();
		}

		$data['items_module_allowed'] = $this->Employee->has_grant('items', $this->Employee->get_logged_in_employee_info()->person_id);
		$data['change_price'] = $this->Employee->has_grant('sales_change_price', $this->Employee->get_logged_in_employee_info()->person_id);

		$invoice_number = $this->sale_lib->get_invoice_number();

		if ($this->sale_lib->get_invoice_number() == NULL)
		{
			$invoice_number = $this->appconfig->get('sales_invoice_format');
		}

		$data['invoice_number'] = $invoice_number;

		$data['print_after_sale'] = $this->sale_lib->is_print_after_sale();
		$data['price_work_orders'] = $this->sale_lib->is_price_work_orders();

		$data['pos_mode'] = $data['mode'] == 'sale' || $data['mode'] == 'return';

		$data['quote_number'] = $this->sale_lib->get_quote_number();
		$data['work_order_number'] = $this->sale_lib->get_work_order_number();

		//TODO: the if/else set below should be converted to a switch
		if($this->sale_lib->get_mode() == 'sale_invoice')	//TODO: Duplicated code.
		{
			$data['mode_label'] = lang('Sales.invoice');
			$data['customer_required'] = lang('Sales.customer_required');
		}
		elseif($this->sale_lib->get_mode() == 'sale_quote')
		{
			$data['mode_label'] = lang('Sales.quote');
			$data['customer_required'] = lang('Sales.customer_required');
		}
		elseif($this->sale_lib->get_mode() == 'sale_work_order')
		{
			$data['mode_label'] = lang('Sales.work_order');
			$data['customer_required'] = lang('Sales.customer_required');
		}
		elseif($this->sale_lib->get_mode() == 'return')
		{
			$data['mode_label'] = lang('Sales.return');
			$data['customer_required'] = lang('Sales.customer_optional');
		}
		else
		{
			$data['mode_label'] = lang('Sales.receipt');
			$data['customer_required'] = lang('Sales.customer_optional');
		}

		$data = $this->xss_clean($data);

		echo view("sales/register", $data);
	}

	public function receipt(int $sale_id)
	{
		$data = $this->_load_sale_data($sale_id);
		echo view('sales/receipt', $data);
		$this->sale_lib->clear_all();
	}

	public function invoice(int $sale_id)
	{
		$data = $this->_load_sale_data($sale_id);

		echo view('sales/'.$data['invoice_view'], $data);
		$this->sale_lib->clear_all();
	}

	public function edit(int $sale_id)
	{
		$data = [];

		$sale_info = $this->xss_clean($this->sale->get_info($sale_id)->getRowArray());
		$data['selected_customer_id'] = $sale_info['customer_id'];
		$data['selected_customer_name'] = $sale_info['customer_name'];
		$employee_info = $this->Employee->get_info($sale_info['employee_id']);
		$data['selected_employee_id'] = $sale_info['employee_id'];
		$data['selected_employee_name'] = $this->xss_clean($employee_info->first_name . ' ' . $employee_info->last_name);
		$data['sale_info'] = $sale_info;
		$balance_due = round($sale_info['amount_due'] - $sale_info['amount_tendered'] + $sale_info['cash_refund'], totals_decimals(), PHP_ROUND_HALF_UP);
		
		if(!$this->sale_lib->reset_cash_rounding() && $balance_due < 0)
		{
			$balance_due = 0;
		}

		$data['payments'] = [];

		foreach($this->sale->get_sale_payments($sale_id)->getResult() as $payment)
		{
			foreach(get_object_vars($payment) as $property => $value)
			{
				$payment->$property = $this->xss_clean($value);
			}
			$data['payments'][] = $payment;
		}

		$data['payment_type_new'] = PAYMENT_TYPE_UNASSIGNED;
		$data['payment_amount_new'] = $balance_due;

		$data['balance_due'] = $balance_due != 0;

		// don't allow gift card to be a payment option in a sale transaction edit because it's a complex change
		$payment_options = $this->sale->get_payment_options(FALSE);

		if($this->sale_lib->reset_cash_rounding())
		{
			$payment_options[lang('Sales.cash_adjustment')] = lang('Sales.cash_adjustment');
		}

		$data['payment_options'] = $this->xss_clean($payment_options);

		// Set up a slightly modified list of payment types for new payment entry
		$payment_options["--"] = lang('Common.none_selected_text');

		$data['new_payment_options'] = $this->xss_clean($payment_options);

		echo view('sales/form', $data);
	}

	public function delete(int $sale_id = -1, bool $update_inventory = TRUE)	//TODO: Replace -1 with a constant
	{
		$employee_id = $this->Employee->get_logged_in_employee_info()->person_id;
		$has_grant = $this->Employee->has_grant('sales_delete', $employee_id);

		if(!$has_grant)
		{
			echo json_encode (['success' => FALSE, 'message' => lang('Sales.not_authorized')]);
		}
		else
		{
			$sale_ids = $sale_id == -1 ? $this->request->getPost('ids') : [$sale_id];	//TODO: Replace -1 with a constant

			if($this->sale->delete_list($sale_ids, $employee_id, $update_inventory))
			{
				echo json_encode ([
					'success' => TRUE,
					'message' => lang('Sales.successfully_deleted') . ' ' . count($sale_ids) . ' ' . lang('Sales.one_or_multiple'),
					'ids' => $sale_ids
				]);
			}
			else
			{
				echo json_encode (['success' => FALSE, 'message' => lang('Sales.unsuccessfully_deleted')]);
			}
		}
	}

	public function restore(int $sale_id = -1, bool $update_inventory = TRUE)	//TODO: Replace -1 with a constant
	{
		$employee_id = $this->Employee->get_logged_in_employee_info()->person_id;
		$has_grant = $this->Employee->has_grant('sales_delete', $employee_id);

		if(!$has_grant)
		{
			echo json_encode (['success' => FALSE, 'message' => lang('Sales.not_authorized')]);
		}
		else
		{
			$sale_ids = $sale_id == -1 ? $this->request->getPost('ids') : [$sale_id];	//TODO: Replace -1 with a constant

			if($this->sale->restore_list($sale_ids, $employee_id, $update_inventory))
			{
				echo json_encode ([
					'success' => TRUE,
					'message' => lang('Sales.successfully_restored') . ' ' . count($sale_ids) . ' ' . lang('Sales.one_or_multiple'),
					'ids' => $sale_ids
				]);
			}
			else
			{
				echo json_encode (['success' => FALSE, 'message' => lang('Sales.unsuccessfully_restored')]);
			}
		}
	}

	/**
	 * This saves the sale from the update sale view (sales/form).
	 * It only updates the sales table and payments.
	 * @param int $sale_id
	 */
	public function save(int $sale_id = -1)	//TODO: Replace -1 with a constant
	{
		$newdate = $this->request->getPost('date');
		$employee_id = $this->Employee->get_logged_in_employee_info()->person_id;

		$date_formatter = date_create_from_format($this->appconfig->get('dateformat') . ' ' . $this->appconfig->get('timeformat'), $newdate);
		$sale_time = $date_formatter->format('Y-m-d H:i:s');

		$sale_data = [
			'sale_time' => $sale_time,
			'customer_id' => $this->request->getPost('customer_id') != '' ? $this->request->getPost('customer_id') : NULL,
			'employee_id' => $this->request->getPost('employee_id') != '' ? $this->request->getPost('employee_id') : NULL,
			'comment' => $this->request->getPost('comment'),
			'invoice_number' => $this->request->getPost('invoice_number') != '' ? $this->request->getPost('invoice_number') : NULL
		];

		// In order to maintain tradition the only element that can change on prior payments is the payment type
		$payments = [];
		$amount_tendered = 0;
		$number_of_payments = $this->request->getPost('number_of_payments');
		for($i = 0; $i < $number_of_payments; ++$i)
		{
			$payment_id = $this->request->getPost('payment_id_' . $i);
			$payment_type = $this->request->getPost('payment_type_' . $i);
			$payment_amount = $this->request->getPost('payment_amount_' . $i);
			$refund_type = $this->request->getPost('refund_type_' . $i);
			$cash_refund = $this->request->getPost('refund_amount_' . $i);

			if($payment_type == lang('Sales.cash_adjustment'))
			{
				$cash_adjustment = CASH_ADJUSTMENT_TRUE;
			}
			else
			{
				$cash_adjustment = CASH_ADJUSTMENT_FALSE;
			}

			if(!$cash_adjustment)
			{
				$amount_tendered += $payment_amount - $cash_refund;
			}

			// if the refund is not cash ...
			if(empty(strstr($refund_type, lang('Sales.cash'))))	//TODO: This if and the one below can be combined.
			{
				// ... and it's positive ...
				if($cash_refund > 0)
				{
					// ... change it to be a new negative payment (a "non-cash refund")
					$payment_type = $refund_type;
					$payment_amount = $payment_amount - $cash_refund;
					$cash_refund = 0.00;
				}
			}


			$payments[] = [
				'payment_id' => $payment_id,
				'payment_type' => $payment_type,
				'payment_amount' => $payment_amount,
				'cash_refund' => $cash_refund,
				'cash_adjustment' => $cash_adjustment,
				'employee_id' => $employee_id
			];
		}

		$payment_id = -1;	//TODO: Replace -1 with a constant
		$payment_amount = $this->request->getPost('payment_amount_new');
		$payment_type = $this->request->getPost('payment_type_new');

		if($payment_type != PAYMENT_TYPE_UNASSIGNED && $payment_amount <> 0)
		{
			$cash_refund = 0;
			if($payment_type == lang('Sales.cash_adjustment'))
			{
				$cash_adjustment = CASH_ADJUSTMENT_TRUE;
			}
			else
			{
				$cash_adjustment = CASH_ADJUSTMENT_FALSE;
				$amount_tendered += $payment_amount;
				$sale_info = $this->sale->get_info($sale_id)->getRowArray();

				if($amount_tendered > $sale_info['amount_due'])
				{
					$cash_refund = $amount_tendered - $sale_info['amount_due'];
				}
			}

			$payments[] = [
				'payment_id' => $payment_id,
				'payment_type' => $payment_type,
				'payment_amount' => $payment_amount,
				'cash_refund' => $cash_refund,
				'cash_adjustment' => $cash_adjustment,
				'employee_id' => $employee_id
			];
		}

		$this->inventory->update('POS '.$sale_id, ['trans_date' => $sale_time]);	//TODO: Reflection Exception
		if($this->sale->update($sale_id, $sale_data, $payments))
		{
			echo json_encode (['success' => TRUE, 'message' => lang('Sales.successfully_updated'), 'id' => $sale_id]);
		}
		else
		{
			echo json_encode (['success' => FALSE, 'message' => lang('Sales.unsuccessfully_updated'), 'id' => $sale_id]);
		}
	}

	/**
	 * This is used to cancel a suspended pos sale, quote.
	 * Completed sales (POS Sales or Invoiced Sales) can not be removed from the system
	 * Work orders can be canceled but are not physically removed from the sales history
	 */
	public function cancel()
	{
		$sale_id = $this->sale_lib->get_sale_id();
		if($sale_id != -1 && $sale_id != '')	//TODO: Replace -1 with a constant
		{
			$sale_type = $this->sale_lib->get_sale_type();

			if($this->appconfig->get('dinner_table_enable') == TRUE)
			{
				$dinner_table = $this->sale_lib->get_dinner_table();
				$this->dinner_table->release($dinner_table);
			}

			if($sale_type == SALE_TYPE_WORK_ORDER)
			{
				$this->sale->update_sale_status($sale_id, CANCELED);
			}
			else
			{
				$this->sale->delete($sale_id, FALSE);
				$this->session->set_userdata('sale_id', -1);	//TODO: Replace -1 with a constant
			}
		}
		else
		{
			$this->sale_lib->remove_temp_items();
		}

		$this->sale_lib->clear_all();
		$this->_reload();	//TODO: Hungarian notation
	}

	public function discard_suspended_sale()
	{
		$suspended_id = $this->sale_lib->get_suspended_id();
		$this->sale_lib->clear_all();
		$this->sale->delete_suspended_sale($suspended_id);
		$this->_reload();	//TODO: Hungarian notation
	}

	/**
	 * Suspend the current sale.
	 * If the current sale is already suspended then update the existing suspended sale.
	 * Otherwise create it as a new suspended sale
	 */
	public function suspend()
	{
		$sale_id = $this->sale_lib->get_sale_id();
		$dinner_table = $this->sale_lib->get_dinner_table();
		$cart = $this->sale_lib->get_cart();
		$payments = $this->sale_lib->get_payments();
		$employee_id = $this->Employee->get_logged_in_employee_info()->person_id;
		$customer_id = $this->sale_lib->get_customer();
		$invoice_number = $this->sale_lib->get_invoice_number();
		$work_order_number = $this->sale_lib->get_work_order_number();
		$quote_number = $this->sale_lib->get_quote_number();
		$sale_type = $this->sale_lib->get_sale_type();

		if($sale_type == '')
		{
			$sale_type = SALE_TYPE_POS;
		}

		$comment = $this->sale_lib->get_comment();
		$sale_status = SUSPENDED;

		$data = [];
		$sales_taxes = [[], []];

		if($this->sale->save($sale_id, $sale_status, $cart, $customer_id, $employee_id, $comment, $invoice_number, $work_order_number, $quote_number, $sale_type, $payments, $dinner_table, $sales_taxes) == '-1')
		{
			$data['error'] = lang('Sales.unsuccessfully_suspended_sale');
		}
		else
		{
			$data['success'] = lang('Sales.successfully_suspended_sale');
		}

		$this->sale_lib->clear_all();

		$this->_reload($data);	//TODO: Hungarian notation
	}

	/**
	 * List suspended sales
	 */
	public function suspended()
	{
		$data = [];
		$customer_id = $this->sale_lib->get_customer();
		$data['suspended_sales'] = $this->xss_clean($this->sale->get_all_suspended($customer_id));
		echo view('sales/suspended', $data);
	}

	/**
	 * Unsuspended sales are now left in the tables and are only removed
	 * when they are intentionally cancelled.
	 */
	public function unsuspend()
	{
		$sale_id = $this->request->getPost('suspended_sale_id');
		$this->sale_lib->clear_all();

		if($sale_id > 0)
		{
			$this->sale_lib->copy_entire_sale($sale_id);
		}

		// Set current register mode to reflect that of unsuspended order type
		$this->change_register_mode($this->sale_lib->get_sale_type());

		$this->_reload();	//TODO: Hungarian notation
	}

	public function check_invoice_number()
	{
		$sale_id = $this->request->getPost('sale_id');
		$invoice_number = $this->request->getPost('invoice_number');
		$exists = !empty($invoice_number) && $this->sale->check_invoice_number_exists($invoice_number, $sale_id);
		echo !$exists ? 'true' : 'false';
	}

	public function get_filtered(array $cart): array
	{
		$filtered_cart = [];
		foreach($cart as $id => $item)
		{
			if($item['print_option'] == PRINT_ALL) // always include
			{
				$filtered_cart[$id] = $item;
			}
			elseif($item['print_option'] == PRINT_PRICED && $item['price'] != 0)  // include only if the price is not zero
			{
				$filtered_cart[$id] = $item;
			}
			// print_option 2 is never included
		}

		return $filtered_cart;
	}

	public function change_item_number()
	{
		$item_id = $this->request->getPost('item_id');
		$item_number = $this->request->getPost('item_number');
		$this->item->update_item_number($item_id, $item_number);
		$cart = $this->sale_lib->get_cart();
		$x = $this->search_cart_for_item_id($item_id, $cart);
		if($x != NULL)
		{
			$cart[$x]['item_number'] = $item_number;
		}
		$this->sale_lib->set_cart($cart);
	}

	public function change_item_name()
	{
		$item_id = $this->request->getPost('item_id');
		$name = $this->request->getPost('item_name');

		$this->item->update_item_name($item_id, $name);

		$cart = $this->sale_lib->get_cart();
		$x = $this->search_cart_for_item_id($item_id, $cart);

		if($x != NULL)
		{
			$cart[$x]['name'] = $name;
		}

		$this->sale_lib->set_cart($cart);
	}

	public function change_item_description()
	{
		$item_id = $this->request->getPost('item_id');
		$description = $this->request->getPost('item_description');

		$this->item->update_item_description($item_id, $description);

		$cart = $this->sale_lib->get_cart();
		$x = $this->search_cart_for_item_id($item_id, $cart);

		if($x != NULL)
		{
			$cart[$x]['description'] = $description;
		}

		$this->sale_lib->set_cart($cart);
	}

	public function search_cart_for_item_id(int $id, array $array)	//TODO: The second parameter should not be named array perhaps int $needle_item_id, array $shopping_cart
	{
		foreach($array as $key => $val)	//TODO: key and val are not reflective of the contents of the array and should be replaced with descriptive variable names.  Perhaps $cart_haystack => $item_details
		{
			if($val['item_id'] === $id)	//TODO: Then this becomes more readable $item_details['item_id'] === $needle_item_id
			{
				return $key;
			}
		}

		return NULL;
	}
}
?>