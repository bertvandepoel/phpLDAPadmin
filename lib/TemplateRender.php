<?php
// $Header$

/**
 * This class will render the creation or editing of an LDAP entry.
 *
 * @author The phpLDAPadmin development team
 * @package phpLDAPadmin
 */

/**
 * TemplateRender class
 *
 * @package phpLDAPadmin
 * @subpackage Templates
 */
class TemplateRender extends PageRender {
	# Page number
	private $pagelast;

	/** CORE FUNCTIONS **/

	/**
	 * Initialise and Render the TemplateRender
	 */
	public function accept($norender=false) {
		if (DEBUGTMP) printf('<font size=-2>%s:%s</font><br />',time(),__METHOD__);
		if (DEBUGTMP||DEBUGTMPSUB) printf('<font size=-2>* %s [Visit-Start:%s]</font><br />',__METHOD__,get_class($this));

		$tree = get_cached_item($this->server_id,'tree');
		if (! $tree)
			$tree = Tree::getInstance($this->server_id);

		$treeitem = $tree->getEntry($this->dn);

		# If we have a DN, and no template_id, see if the tree has one from last time
		if ($this->dn && is_null($this->template_id) && $treeitem && $treeitem->getTemplate())
			$this->template_id = $treeitem->getTemplate();

		# Check that we have a valid template, or present a selection
		# @todo change this so that the modification templates rendered are the ones for the objectclass of the dn.
		if (! $this->template_id)
			$this->template_id = $this->getTemplateChoice();

		if ($treeitem)
			$treeitem->setTemplate($this->template_id);

		$this->page = get_request('page','REQUEST',false,1);

		if ($this->template_id) {
			parent::accept();

			$this->url_base = sprintf('server_id=%s&dn=%s',
				$this->getServerID(),rawurlencode($this->template->getDN()));
			$this->layout['hint'] = sprintf('<td class="icon"><img src="%s/light.png" alt="%s" /></td><td colspan="3"><span class="hint">%%s</span></td>',
				IMGDIR,_('Hint'));
			$this->layout['action'] = '<td class="icon"><img src="%s/%s" alt="%s" /></td><td><a href="cmd.php?%s" title="%s">%s</a></td>';
			$this->layout['actionajax'] = '<td class="icon"><img src="%s/%s" alt="%s" /></td><td><a href="cmd.php?%s" title="%s" onclick="return displayAJ(\'BODY\',\'%s\',\'%s\');">%s</a></td>';

			# If we dont want to render this template automatically, we'll return here.
			if ($norender)
				return;

			$this->visitStart();

			foreach ($this->template->getAttributes(true) as $attribute) {
				# Evaluate our defaults
				if ($attribute->getAutoValue())
					$this->get('Default',$attribute,
						$this->template->getContainer() ? $this->template->getContainer() : $this->getServer()->getContainerPath($this->template->getDN()),
						'autovalue');

				# If this is the default template, we should mark all our attributes to show().
				if (($this->template->getID() == 'none') && (! $attribute->isInternal())
					&& (($this->template->getContext() == 'edit' && $this->template->getID() == 'none')
						|| ($this->template->getContext() == 'create' && $attribute->getName() != 'objectclass')))
					$attribute->show();
			}

			if (DEBUGTMP||DEBUGTMPSUB) printf('<font size=-2>* %s [Visit-End:%s]</font><br />',__METHOD__,get_class($this));

			$this->visitEnd();
		}
	}

	protected function getDefaultAttribute($attribute,$container,$type) {
		switch ($type) {
			case 'autovalue':
				$autovalue = $attribute->getAutoValue();

				break;

			case 'helpervalue':
				$autovalue = $attribute->getHelperValue();

				break;

			default:
				system_message(array(
					'title'=>_('Unknown Default Attribute context'),
					'body'=>sprintf('%s (<b>%s</b>)',_('A call was made to getDefaultAttribute() with an unkown context'),$type),
					'type'=>'warn'));

				return;
		}

		$args = explode(';',$autovalue['args']);
		$server = $this->getServer();
		$vals = '';

		switch ($autovalue['function']) {
			/**
			 * Function enables normal PHP functions to be called to evaluate a value.
			 * eg: =php.Function(date;dmY)
			 *
			 * All arguments will be passed to the function, and its value returned.
			 * If this used used in a POST context, the attribute values can be used as arguments.
			 *
			 * Mandatory Arguments:
			 * * arg 0
			 *   - php Function to call
			 *
			 * Additional arguments will be passed to the function.
			 */
			case 'Function':
				$function = array_shift($args);

				if (count($args) && count($args) > 1) {
					system_message(array(
						'title'=>_('Too many arguments'),
						'body'=>sprintf('%s (<b>%s</b>)',_('Function() only takes two arguments and more than two were specified'),count($args)),
						'type'=>'warn'));

					return;
				}

				$function_args = explode(',',$args[0]);

				if (function_exists($function))
					$vals = call_user_func_array($function,$function_args);

				else
					system_message(array(
						'title'=>_('Function doesnt exist'),
						'body'=>sprintf('%s (<b>%s</b>)',_('An attempt was made to call a function that doesnt exist'),$function),
						'type'=>'warn'));

				break;

			/**
			 * GetNextNumber will query the LDAP server and calculate the next number based on the query
			 * eg: <![CDATA[=php.GetNextNumber(/;gidNumber;false;(&(objectClass=posixGroup));*2,+1000)]]>
			 *
			 * Mandatory Arguments:
			 * * arg 0
			 *   - "$" => 'auto_number','search_base' in config file
			 *   - "/",".",".." => get container parent as usual
			 *
			 * * arg 1
			 *   - attribute to query for
			 *
			 * Optional Arguments:
			 * * arg 2 (pool mechanism only)
			 *   - "true" increments attribute by 1
			 *   - "false" do nothing
			 *
			 * * arg 3 (pool mechanism only)
			 *   - ldap filter (must match one entry only in container)
			 *
			 * * arg 4
			 *   - calculus on number, eg:
			 *   - *2,+1000 => number = (2*number) + 1000
			 *
			 * * arg 5
			 *   - Min number
			 */
			case 'GetNextNumber':
				# If the attribute already has values, we'll return
				if ($type == 'autovalue' && $attribute->getValues())
					return;

				if ($args[0] == '$')
					$args[0] = $server->getValue($this->server_id,'auto_number','search_base');

				$container = $server->getContainerPath($container,$args[0]);

				$vals = get_next_number($container,$args[1],
					(! empty($args[2]) && ($args[2] == 'false')) ? false : true,
					(! empty($args[3])) ? $args[3] : false,
					(! empty($args[5])) ? $args[5] : null);

				# Operate calculus on next number.
				if (! empty($args[4])) {
					$mod = explode(',',$args[4]);
					$next_number = $vals;

					foreach ($mod as $calc) {
						$operand = $calc{0};
						$operator = substr ($calc,1);

						switch ($operand) {
							case '*':
								$next_number = $next_number * $operator;
								break;

							case '+':
								$next_number = $next_number + $operator;
								break;

							case '-':
								$next_number = $next_number - $operator;
								break;

							case '/':
								$next_number = $next_number / $operator;
								break;
						}
					}

					$vals = $next_number;
				}

				break;

			/**
			 * MultiList will query the LDAP server and provide a multi select list of values
			 * eg: <![CDATA[<value>=php.MultiList(/;(objectClass=posixAccount);uid)</value>]]>
			 *
			 * eg: <![CDATA[=php.MultiList(/;(&(objectClass=posixAccount)(uid=groupA*));uid;%cn/U% (%gidNumber%);memberUid;dmdName=users,root => cn=root;nobody => cn=nobody;gidNumber;10;(gidNuber=%gidNumber%);uid)]]>
			 *
			 * Mandatory Arguments:
			 * * arg 0
			 *   - container, to query from current position
			 *   - "/",".",".." => get container parent as usual
			 *
			 * * arg 1
			 *   - LDAP filter. May include '%attr%', it will be expanded.
			 *
			 * * arg2
			 *   - list attribute key
			 *
			 * Optional Arguments:
			 * * arg3
			 *   - select display (plus modifier /C: Capitalize)
			 *   - replaced by %arg 2% if not given
			 *
			 * * arg 4
			 *   - the value furnished in output - must be attribute id. replaced by arg 2 if not given
			 *
			 * * arg 5
			 *   - container override
			 *
			 * * arg 6
			 *   - csv list (, separator) of added values. syntax: key => display_attribute=value; key...
			 *
			 * * arg 7
			 *   - csv list (, separator) of sort attributes (less to more important)
			 *
			 * * arg 8
			 *   - size of displayed list (default: 10 lines)
			 *
			 * * arg 9
			 *   - preselected values filter. see arg 1.
			 *
			 * * arg 10
			 *   - key of preselected values. replaced by arg 4 if not given. replaced by arg 2 if both are not given.
			 *
			 * * arg 11
			 *   - base dn override for preselected values
			 *
			 * @todo This could probably be merged with PickList
			 */
			case 'MultiList':
				# arg5 overrides our container
				if (empty($args[5]))
					$container = $server->getContainerPath($container,$args[0]);
				else
					$container = $args[5];

				# Process filter (arg 1), eventually replace %attr% by it's value setted in a previous page.
				preg_match_all('/%(\w+)(\|.+)?(\/[lUC])?%/U',$args[1],$filtermatchall);

				# @todo this section needs to be validated/tested
				if (isset($_REQUEST['form'])) {
					$formvalues = array_change_key_case($_REQUEST['form']);

					foreach ($filtermatchall[1] as $arg) {
						$value = $formvalues[strtolower($arg)];
						$args[1] = preg_replace("/%($arg)(\|.+)?(\/[lU])?%/U",$value,$args[1]);
					}
				}

				$args[3] = ! empty($args[3]) ? $args[3] : "%{$args[2]}%";

				preg_match_all('/%(\w+)(\|.+)?(\/[lUC])?%/U',$args[3],$matchall);
				//print_r($matchall); // -1 = highlevel match, 1 = attr, 2 = subst, 3 = mod

				$attrs = array_unique(array_merge($matchall[1],array($args[2])));

				 # arg7 is sort attributes
				if (isset($args[7])) {
					$sort_attrs = explode(',',$args[7]);
					$attrs = array_merge($attrs,$sort_attrs);
				}

				$picklistvalues = return_ldap_hash($container,$args[1],$args[2],$attrs,(isset($args[7]) && ($args[7])) ? $sort_attrs : false);

				# arg6 is a set of fixed values to add to search result
				if (isset($args[6])) {
					$fixedvalues = explode(',',$args[6]);

					foreach ($fixedvalues as $fixedvalue) {
						if (empty($fixedvalue))
							continue;

						$fixedvalue = preg_split('/=\>/',$fixedvalue);
						$displayvalue = explode('=',$fixedvalue[1]);

						$newvalue[trim($fixedvalue[0])] = array($args[2]=>trim($fixedvalue[0]),
							trim($displayvalue[0])=>trim($displayvalue[1]));

						$picklistvalues = array_merge($picklistvalues,$newvalue);
					}
				}

				 /* arg 9 is the search filter for already selected values, with criteriai eventually
				    coming from previous pages (eg: %uid%) */
				if (isset($args[9])) {
					preg_match_all('/%(\w+)(\|.+)?(\/[lUC])?%/U',$args[9],$matchallinlist);

					foreach ($matchallinlist[1] as $arg) {
						$value=$formvalues[strtolower($arg)];

						$args[9] = preg_replace('/%('.$arg.')(\|.+)?(\/[lU])?%/U',$value,$args[9]);
					}

					# arg11 overrides container dn for selected values
					if (! empty($args[11]))
						$container = $args[11];

					$inpicklistvalues = return_ldap_hash($container,$args[9],$args[2],$attrs);
				}

				$vals = array();

				foreach ($picklistvalues as $key => $values) {
					$display = $args[3];

					foreach ($matchall[1] as $key => $arg) {
						if (isset($values[$arg]))
							$disp_val = $values[$arg];
						else
							$disp_val = '';

						if (is_array($disp_val))
							$disp_val = $disp_val[0];

						if ($matchall[3][$key])
							switch ($matchall[3][$key]) {
								case '/l':
								# lowercase
									if (function_exists('mb_convert_case'))
										$disp_val = mb_convert_case($disp_val,MB_CASE_LOWER,'utf-8');
									else
										$disp_val = strtolower($disp_val);

									break;

								case '/U':
								# uppercase
									if (function_exists('mb_convert_case'))
										$disp_val = mb_convert_case($disp_val,MB_CASE_UPPER,'utf-8');
									else
										$disp_val = strtoupper($disp_val);

										break;

								case '/C':
								# capitalize
									if (function_exists('mb_convert_case'))
										$disp_val = mb_convert_case($disp_val,MB_CASE_TITLE,'utf-8');
									else
										$disp_val = ucfirst($disp_val);

									break;

								default:
									break;
							}

						# make value a substring of
						preg_match_all('/^\|([0-9]*)-([0-9]*)$/',trim($matchall[2][$key]),$substrarray);

						if ((isset($substrarray[1][0]) && $substrarray[1][0]) || (isset($substrarray[2][0]) && $substrarray[2][0])) {
							$begin = $substrarray[1][0] ? $substrarray[1][0] : '0';
							$end = $substrarray[2][0] ? $substrarray[2][0] : strlen($disp_val);

							if (function_exists('mb_substr'))
								$disp_val = mb_substr($disp_val,$begin,$end,'utf-8');
							else
								$disp_val = substr($disp_val,$begin,$end);
						}

						$display = preg_replace("/%($arg)(\|.+)?(\/[lUC])?%/U",$disp_val,$display);
					}

					if (! isset($picklist[$values[$args[2]]])) {
						$vals[$values[$args[2]]] = $display;
						$picklist[$values[$args[2]]] = true;
					}
				}

				break;

			/**
			 * PasswordEncryptionTypes will return a list of our support password encryption types
			 * eg: =php.PasswordEncryptionTypes()
			 *
			 * This function doesnt use any arguments
			 */
			case 'PasswordEncryptionTypes':
				$vals = password_types();

				break;

			/**
			 * PickList will query the LDAP server and provide a select list of values
			 * eg: <![CDATA[=php.PickList(/;(&(objectClass=posixGroup));gidNumber;%cn%)]]>
			 *
			 * eg: <![CDATA[=php.PickList(/;(&(objectClass=sambaGroupMapping)(|(cn=domain administrator)(cn=domain users)(cn=domain guests)))|sambaSID|%cn% (%sambaSID%)|sambaPrimaryGroupSID|dmdname=users,dmdName=groups,dc=example,dc=com|S-1-5-XX-YYY => cn=Administrators,S-1-5-XX-YYY => cn=Users,S-1-5-XX-YYY => cn=Guests,S-1-5-XX-YYY => cn=power users|cn)]]>
			 *
			 * Mandatory Arguments:
			 * * arg 0
			 *   - container, to query from current position
			 *   - "/",".",".." => get container parent as usual
			 *
			 * * arg 1
			 *   - LDAP filter.
			 *
			 * * arg2
			 *   - list attribute key
			 *
			 * * arg3
			 *   - select display
			 *
			 * Optional Arguments:
			 * * arg4
			 *   - output attribute
			 *
			 * * arg5
			 *   - container override
			 *
			 * * arg6
			 *   - csv list (, separator) of added values. syntax: key => display_attribute=value; key...
			 *
			 * * arg7
			 *   - csv list (, separator) of sort attributes (less to more important)
			 */
			case 'PickList':
				# arg5 overrides our container
				if (empty($args[5]))
					$container = $server->getContainerPath($container,$args[0]);
				else
					$container = $args[5];

				# Extract the attributes to use in the display
				preg_match_all('/%(\w+)(\|.+)?(\/[lU])?%/U',$args[3],$matchall);
				//print_r($matchall); // -1 = highlevel match, 1 = attr, 2 = subst, 3 = mod

				$attrs = array_unique(array_merge($matchall[1],array($args[2])));

				# The query results are sorted by arg7
				if (! empty($args[7])) {
					$sort_attrs = explode(',',$args[7]);
					$attrs = array_merge($attrs,$sort_attrs);
				}

				$picklistvalues = return_ldap_hash($container,$args[1],$args[2],$attrs,(isset($args[7]) && ($args[7])) ? $sort_attrs : false);

				if (! empty($args[6])) {
					$fixedvalues = explode(',',$args[6]);

					foreach ($fixedvalues as $fixedvalue) {
						$fixedvalue = preg_split('/=\>/',$fixedvalue);
						$displayvalue = explode('=',$fixedvalue[1]);

						$newvalue[trim($fixedvalue[0])] = array($args[2]=>trim($fixedvalue[0]),
							trim($displayvalue[0])=>trim($displayvalue[1]));

						$picklistvalues = array_merge($picklistvalues,$newvalue);
					}
				}

				$vals = array();

				foreach ($picklistvalues as $key => $values) {
					$display = $args[3];

					foreach ($matchall[1] as $arg)
						if (isset($values[$arg]))
							$display = preg_replace("/%($arg)(\|.+)?(\/[lU])?%/U",$values[$arg],$display);
						else
							$display = preg_replace("/%($arg)(\|.+)?(\/[lU])?%/U",'',$display);

					if (! isset($picklist[$values[$args[2]]])) {
						$vals[$values[$args[2]]] = $display;
						$picklist[$display] = true;
					}
				}

				break;

			/**
			 * RandomPassword will create a random password for the value.
			 * eg: =php.RandomPassword()
			 *
			 * When calling the attribute Javascript it will generate a random password.
			 *
			 * This function doesnt use any arguments
			 */
			case 'RandomPassword':
				break;
		}

		switch ($type) {
			case 'autovalue':
				if (! is_array($vals))
					$attribute->autoValue(array($vals));
				else
					$attribute->autoValue($vals);

				break;

			case 'helpervalue':
				return $vals;
		}
	}

	/**
	 * Set the mode of the TemplateRender
	 * Applicable modes are "create" or "edit"
	 */
	protected function getMode() {
		if ($this->dn)
			return 'modification';
		elseif ($this->container)
			return 'creation';
		else
			debug_dump_backtrace(sprintf('Unknown mode for %s',__METHOD__),1);
	}

	/**
	 * Is the default template enabled?
	 */
	protected function haveDefaultTemplate() {
		if ($_SESSION[APPCONFIG]->getValue('appearance','disable_default_template'))
			return false;
		else
			return true;
	}

	/**
	 * Present a list of available templates for creating and editing LDAP entries
	 */
	protected function drawTemplateChoice() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$this->drawTitle();
		$this->drawSubTitle();
		echo "\n";

		switch ($this->getMode()) {
			case 'creation':
				$msg = _('Select a template for the creation process');
				break;

			case 'modification':
				$msg = _('Select a template to edit the entry');
				break;
		}

		$avail_templates = $this->getTemplates();
		$templates = $avail_templates->getTemplates($this->getMode());
		printf('<center><h3>%s</h3></center>',$msg);

		$href_parms = array_to_query_string($_GET,array('meth'));
		printf('<form name="template_choice_form" action="cmd.php?%s" method="post">',htmlspecialchars($href_parms));
		echo "\n\n";

		foreach ($_POST as $p => $v)
			printf('<input type="hidden" name="%s" value="%s">',$p,$v);
		echo "\n\n";

		echo '<table class="forminput" width="100%" border=0>';
		echo '<tr>';
		printf('<td class="heading" style="vertical-align: top">%s:</td>',_('Templates'));
		echo '<td>';
		echo '<table>';

		$i = -1;
		$nb_templates = count($templates);

		if ($this->haveDefaultTemplate())
			$nb_templates++;

		foreach ($templates as $name => $details) {
			$i++;

			$isInValid = $details->isInValid();

			if (($regexp = $details->getRegExp())
				&& (($this->getMode() == 'creation' && ! @preg_match('/'.$regexp.'/i',$this->container))
					|| ($this->getMode() == 'modification' && ! @preg_match('/'.$regexp.'/i',$this->dn))))
				$isInValid = _('This template is not valid in this container');

			# Balance the columns properly
			if (($nb_templates % 2 == 0 && $i == intval($nb_templates / 2)) ||
				($nb_templates % 2 == 1 && $i == intval($nb_templates / 2) + 1)) {
				echo '</table></td><td><table>';
			}

			echo "\n";
			echo '<tr>';

			if ($isInValid)
				printf('<td class="icon"><img src="%s/disabled.png" alt="Error" /></td>',IMGDIR);

			else {
				if (isAjaxEnabled())
					printf('<td><input type="radio" name="template" value="%s" id="%s" onclick="return displayAJ(\'BODY\',\'%s&amp;template=%s\',\'%s\');" /></td>',
						htmlspecialchars($details->getID()),htmlspecialchars($details->getID()),htmlspecialchars($href_parms),$details->getID(),_('Retieving DN'));
				else
					printf('<td><input type="radio" name="template" value="%s" id="%s" onclick="document.forms.template_choice_form.submit()" /></td>',
						htmlspecialchars($details->getID()),htmlspecialchars($details->getID()));
			}

			printf('<td class="icon"><label for="%s"><img src="%s" alt="" /></label></td>',
				htmlspecialchars($details->getID()),$details->getIcon());
			printf('<td class="label"><label for="%s">',
				htmlspecialchars($details->getID()));

			if ($isInValid)
				printf('<span style="color: gray"><acronym title="%s">',_($isInValid));

			echo _($details->getTitle());

			if ($isInValid)
				echo '</acronym></span>';

			echo '</label></td>';
			echo '</tr>';
		}
		echo "\n";

		# Default template
		if ($this->haveDefaultTemplate()) {
			$i++;

			# Balance the columns properly
			if (($nb_templates % 2 == 0 && $i == intval($nb_templates / 2)) ||
				($nb_templates % 2 == 1 && $i == intval($nb_templates / 2) + 1)) {
				echo '</table></td><td><table>';
			}

			echo '<tr>';
			if (isAjaxEnabled())
				printf('<td><input type="radio" name="template" value="none" id="none" onclick="return displayAJ(\'BODY\',\'%s&amp;template=%s\',\'%s\');" /></td>',
					htmlspecialchars($href_parms),'none',_('Retieving DN'));
			else
				echo '<td><input type="radio" name="template" value="none" id="none" onclick="document.forms.template_choice_form.submit()" /></td>';

			printf('<td class="icon"><label for="none"><img src="%s/object.png" alt="" /></label></td>',IMGDIR);
			printf('<td class="label"><label for="none">%s</label></td>',_('Default'));
			echo '</tr>';
		}

		echo '</table>';
		echo '</td></tr>';

		echo '</table>';
		echo '</form>';
	}

	/** VISIT METHODS **/

	/**
	 * This function will setup our template object (read LDAP for current values, read $_REQUEST for new values, etc)
	 * so that it can be rendered.
	 */
	private function visitStart() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);
		if (DEBUG_ENABLED)
			debug_log('Entered',1,__FILE__,__LINE__,__METHOD__);

		# If we have a DN, then we are an editing template
		if ($this->dn)
			$this->template->setDN(get_request('dn','REQUEST'));

		# Else if we have a container, we are a creating template
		elseif ($this->container)
			$this->template->setContainer(get_request('container','REQUEST'));

		else
			debug_dump_backtrace('Dont know what type of template we are - no DN or CONTAINER?',1);

		# Header
		$this->drawHeader();
	}

	private function visitEnd() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);
		if (DEBUG_ENABLED)
			debug_log('Entered with (%s)',1,__FILE__,__LINE__,__METHOD__,$this->template->getDN());

		foreach ($this->template->getAttributesShown() as $attribute)
			if ($attribute->getPage() > $this->pagelast)
				$this->pagelast = $attribute->getPage();
		echo "\n\n";

		if ($this->template->getContext() == 'create') {
			$this->drawStepTitle($this->page);
			$this->drawStepFormStart($this->page);
			$this->drawStepForm($this->page);
			$this->drawStepFormEnd();

		} else {
			# Draw internal attributes
			if (get_request('show_internal_attrs','REQUEST')) {
				echo '<table class="entry" cellspacing="0" align="center" border=0>';
				$this->drawInternalAttributes();
				echo '</table><br/>';
				echo "\n";
			}

			$this->drawFormStart();

			# To support our AJAX add Attribute
			printf('<div id="ajADD" style="display: %s"></div>','none');

			$this->drawForm();
			$this->drawStepFormEnd();
		}
	}

	/** PAGE DRAWING METHODS **/

	private function drawHeader() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		# Title
		$this->drawTitle();
		$this->drawSubTitle();
		echo "\n";

		# Menu
		$this->drawMenu();
	}

	public function drawTitle($title=null) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (is_null($title))
			switch ($this->getMode()) {
				case 'creation':
					$title = _('Create Object');
					break;

				case 'modification':
					$title = get_rdn($this->dn);
					break;

				default:
					$title = 'Title';
			}

		parent::drawTitle($title);
	}

	public function drawSubTitle($subtitle=null) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if ($subtitle)
			return parent::drawSubTitle($subtitle);

		switch ($this->getMode()) {
			case 'creation':
				$subtitle = sprintf('%s: <b>%s</b>&nbsp;&nbsp;&nbsp;%s: <b>%s</b>',
					_('Server'),$this->getServer()->getName(),
					_('Container'),$this->container);

				if ($this->template_id) {
					$subtitle .= '<br />';
					$subtitle .= sprintf('%s: <b>%s</b>',('Template'),$this->template->getID() != 'none' ? $this->template->getTitle() : _('Default'));
					if ($this->template->getName())
						$subtitle .= sprintf(' (<b>%s</b>)',$this->template->getName(false));
				}

				break;

			case 'modification':
				$subtitle = sprintf('%s: <b>%s</b>&nbsp;&nbsp;&nbsp;%s: <b>%s</b>',
					_('Server'),$this->getServer()->getName(),
					_('Distinguished Name'),$this->dn);

				if ($this->template_id) {
					$subtitle .= '<br />';
					$subtitle .= sprintf('%s: <b>%s</b>',('Template'),$this->template->getID() != 'none' ? $this->template->getTitle() : _('Default'));
					if ($this->template->getName())
						$subtitle .= sprintf(' (<b>%s</b>)',$this->template->getName(false));
				}

				break;
		}

		parent::drawSubTitle($subtitle);
	}

	/** PAGE ENTRY MENU **/

	private function drawMenu() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		# We only have a menu for editing entries.
		if ($this->template->getContext() == 'edit') {

			echo '<table class="menu" width="100%" border=0>';
			echo '<tr>';
			$menuitem_number = 0;

			foreach (array('entryrefresh','showinternal','switchtemplate','entryexport','entrycopy','entrydelete','entryrename','entrycompare','childcreate','addattr','msgdel','childview','childexport','msgschema','msgro','msgmodattr') as $item) {
				$item = $this->getMenuItem($item);

				if ($item) {
					$endofrow = false;
					$start = true;
					$it = ''; // menu item
					$ms = ''; // item message

					if (is_array($item) && count($item) > 0) {
						$it = $item[0];

						if (count($item) > 1)
							$ms = $item[1];

					} else {
						$it = $item;
					}

					if ($it) {
						$menuitem_number++;
						echo $it;

						if ($ms) {
							if (($menuitem_number % 2) == 1) {
								$menuitem_number++;
								echo '<td colspan=2>&nbsp;</td>';
								$endofrow = false;
								$start = false;
							}

							if ($endofrow)
								print $ms;
							else
								echo "</tr><tr>$ms";

							echo '</tr><tr>';
							$endofrow = true;
							$start = false;

						} else {
							if ($menuitem_number > 1 && ($menuitem_number % 2) == 0) {
								echo '</tr><tr>';
								$endofrow = true;
								$start = false;
							}
						}

					} elseif ($ms) {
						if (($menuitem_number % 2) == 1) {
							$menuitem_number++;
							echo '<td colspan=2>&nbsp;</td>';
							$endofrow = false;
							$start = false;
						}

						if ($endofrow || $start)
							print $ms;
						else
							echo "</tr><tr>$ms";

						echo '</tr><tr>';
						$endofrow = true;
						$start = false;
					}

					echo "\n";
				}
			}

			if (($menuitem_number % 2) == 1)
				echo '<td>&nbsp;</td><td>&nbsp;</td>';
			else
				echo '<td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>';

			echo '</tr>';
			echo '</table>';
		}
	}

	/** PAGE ENTRY MENU ITEMS **/

	private function getMenuItem($i) {
		if (DEBUGTMP) printf('<font size=-2>%s (%s)</font><br />',__METHOD__,$i);

		switch ($i) {
			case 'entryrefresh':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('entry_refresh'))
					return $this->getMenuItemRefresh();
				else
					return '';

			case 'switchtemplate':
				return $this->getMenuItemSwitchTemplate();

			case 'entryexport':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('script','export_form'))
					return $this->getMenuItemExportBase();
				else
					return '';

			case 'entrycopy':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('script','copy_form') && ! $this->template->isReadOnly())
					return $this->getMenuItemMove();
				else
					return '';

			case 'showinternal':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('entry_internal_attributes_show'))
					return $this->getMenuItemInternalAttributes();
				else
					return '';

			case 'entrydelete':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('script','delete_form') && ! $this->template->isReadOnly())
					return $this->getMenuItemDelete();
				else
					return '';

			case 'entryrename':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('entry_rename') && ! $this->template->isReadOnly()) {

					# Check if any of the RDN's are read only.
					$rdnro = false;
					foreach ($this->template->getRDNAttributeName() as $attr) {
						$attribute = $this->template->getAttribute($attr);

						if ($attribute && $attribute->isVisible() && ! $attribute->isReadOnly()) {
							$rdnro = true;
							break;
						}
					}

					if (! $rdnro)
						return $this->getMenuItemRename();
				}

				return '';

			case 'msgdel':
				if ($_SESSION[APPCONFIG]->getValue('appearance','show_hints')
					&& $_SESSION[APPCONFIG]->isCommandAvailable('attribute_delete') && ! $this->template->isReadOnly())
					return array('',$this->getDeleteAttributeMessage());
				else
					return '';

			case 'entrycompare':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('entry_compare') && ! $this->template->isReadOnly())
					return $this->getMenuItemCompare();
				else
					return '';

			case 'childcreate':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('entry_create') && ! $this->template->isReadOnly())
					return $this->getMenuItemCreate();
				else
					return '';

			case 'addattr':
				if ($_SESSION[APPCONFIG]->isCommandAvailable('attribute_add') && ! $this->template->isReadOnly())
					return $this->getMenuItemAddAttribute();
				else
					return '';

			case 'childview':
			case 'childexport':
				static $children_count = false;
				static $more_children = false;

				$tree = get_cached_item($this->getServerID(),'tree');
				$tree_item = $tree->getEntry($this->template->getDN());

				if (! $tree_item) {
					$tree->addEntry($this->template->getDN());
					$tree_item = $tree->getEntry($this->template->getDN());
				}

				if ($children_count === false) {
					# Visible children in the tree
					$children_count = count($tree_item->getChildren());
					# Is there filtered children ?
					$more_children = $tree_item->isSizeLimited();

					if (! $children_count || ! $more_children) {
						# All children in ldap
						$all_children = $this->getServer()->getContainerContents(
							$this->template->getDN(),null,$children_count+1,'(objectClass=*)',$_SESSION[APPCONFIG]->getValue('deref','view'),null);

						$more_children = (count($all_children) > $children_count);
					}
				}

				if ($children_count > 0 || $more_children) {
					if ($children_count <= 0)
						$children_count = '';
					if ($more_children)
						$children_count .= '+';

					if ($i == 'childview')
						return $this->getMenuItemShowChildren($children_count);
					elseif ($i == 'childexport' && $_SESSION[APPCONFIG]->isCommandAvailable('export'))
						return $this->getMenuItemExportSub();
					else
						return '';

				} else
					return '';

			case 'msgschema':
				if ($_SESSION[APPCONFIG]->getValue('appearance','show_hints') && $_SESSION[APPCONFIG]->isCommandAvailable('schema'))
					return array('',$this->getViewSchemaMessage());
				else
					return array();

			case 'msgro':
				if ($this->template->isReadOnly())
					return array('',$this->getReadOnlyMessage());
				else
					return array();

			case 'msgmodattr':
				$modified_attrs = array();
				$modified = get_request('modified_attrs','REQUEST',false,array());

				foreach ($this->template->getAttributes(true) as $attribute)
					if (in_array($attribute->getName(),$modified))
						array_push($modified_attrs,$attribute->getFriendlyName());

				if (count($modified_attrs))
					return array('',$this->getModifiedAttributesMessage($modified_attrs));
				else
					return array();

			default:
				return false;
		}
	}

	protected function getDeleteAttributeMessage() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if ($_SESSION[APPCONFIG]->isCommandAvailable('attribute_delete_value') && ! $this->template->isReadOnly())
			return sprintf($this->layout['hint'],_('Hint: To delete an attribute, empty the text field and click save.'));
		else
			return '';
	}

	protected function getModifiedAttributesMessage(&$modified_attributes) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		return sprintf($this->layout['hint'],
			(count($modified_attributes) == 1) ?
			sprintf(_('An attribute (%s) was modified and is highlighted below.'),implode('',$modified_attributes)) :
			sprintf(_('Some attributes (%s) were modified and are highlighted below.'),implode(', ',$modified_attributes)));
	}

	protected function getReadOnlyMessage() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		return sprintf($this->layout['hint'],_('Viewing entry in read-only mode.'));
	}

	protected function getViewSchemaMessage() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		return sprintf($this->layout['hint'],_('Hint: To view the schema for an attribute, click the attribute name.'));
	}

	/** PAGE ENTRY MENU ITEMS DETAILS **/

	private function getMenuItemRefresh() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=template_engine&%s&junk=%s',$this->url_base,random_junk());

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'refresh.png',_('Refresh'),
				htmlspecialchars($href),_('Refresh this entry'),htmlspecialchars($href),_('Reloading'),_('Refresh'));
		else
			return sprintf($this->layout['action'],IMGDIR,'refresh.png',_('Refresh'),
				htmlspecialchars($href),_('Refresh this entry'),_('Refresh'));
	}

	protected function getMenuItemSwitchTemplate() {
		$href = sprintf('cmd=template_engine&%s&template=',$this->url_base);

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'catalog.png',_('Switch Template'),
				htmlspecialchars($href),_('Change to another template'),htmlspecialchars($href),_('Loading'),_('Switch Template'));
		else
			return sprintf($this->layout['action'],IMGDIR,'catalog.png',_('Switch Template'),
				htmlspecialchars($href),_('Change to another template'),_('Switch Template'));
	}

	protected function getMenuItemExportBase() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=export_form&%s&scope=base',$this->url_base);

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'save.png',_('Export'),
				htmlspecialchars($href),_('Save a dump of this object'),htmlspecialchars($href),_('Loading'),_('Export'));
		else
			return sprintf($this->layout['action'],IMGDIR,'save.png',_('Export'),
				htmlspecialchars($href),_('Save a dump of this object'),_('Export'));
	}

	private function getMenuItemMove() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=copy_form&%s',$this->url_base);

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'cut.png',_('Cut'),
				htmlspecialchars($href),_('Copy this object to another location, a new DN, or another server'),
				htmlspecialchars($href),_('Loading'),_('Copy or move this entry'));
		else
			return sprintf($this->layout['action'],IMGDIR,'cut.png',_('Cut'),
				htmlspecialchars($href),_('Copy this object to another location, a new DN, or another server'),
				_('Copy or move this entry'));
	}

	protected function getMenuItemInternalAttributes() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (get_request('show_internal_attrs','REQUEST')) {
			$href = sprintf('cmd=template_engine&%s&junk=',$this->url_base,random_junk());

			return sprintf($this->layout['action'],IMGDIR,'tools-no.png',_('Hide'),
				htmlspecialchars($href),'',_('Hide internal attributes'));

		} else {
			$href = sprintf('cmd=template_engine&show_internal_attrs=true&%s',$this->url_base);

			return sprintf($this->layout['action'],IMGDIR,'tools.png',_('Show'),
				htmlspecialchars($href),'',_('Show internal attributes'));
		}
	}

	private function getMenuItemDelete() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=delete_form&%s',$this->url_base);

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'trash.png',_('Trash'),
				htmlspecialchars($href),_('You will be prompted to confirm this decision'),
				htmlspecialchars($href),_('Loading'),_('Delete this entry'));
		else
			return sprintf($this->layout['action'],IMGDIR,'trash.png',_('Trash'),
				htmlspecialchars($href),_('You will be prompted to confirm this decision'),_('Delete this entry'));
	}

	protected function getMenuItemRename() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=rename_form&%s&template=%s',$this->url_base,$this->template->getID());

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'rename.png',_('Rename'),
				htmlspecialchars($href),_('Rename this entry'),htmlspecialchars($href),_('Loading'),_('Rename'));
		else
			return sprintf($this->layout['action'],IMGDIR,'rename.png',_('Rename'),
				htmlspecialchars($href),_('Rename this entry'),_('Rename'));
	}

	protected function getMenuItemCompare() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=compare_form&%s',$this->url_base);

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'compare.png',_('Compare'),
				htmlspecialchars($href),_('Compare this entry with another'),
				htmlspecialchars($href),_('Loading'),_('Compare with another entry'));
		else
			return sprintf($this->layout['action'],IMGDIR,'compare.png',_('Compare'),
				htmlspecialchars($href),_('Compare this entry with another'),_('Compare with another entry'));
	}

	protected function getMenuItemCreate() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=template_engine&server_id=%s&container=%s',$this->getServerID(),rawurlencode($this->template->getDN()));

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'star.png',_('Create'),
				htmlspecialchars($href),_('Create a child entry'),
				htmlspecialchars($href),_('Loading'),_('Create a child entry'));
		else
			return sprintf($this->layout['action'],IMGDIR,'star.png',_('Create'),
				htmlspecialchars($href),_('Create a child entry'),_('Create a child entry'));
	}

	protected function getMenuItemAddAttribute() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (! $this->template->getAvailAttrs())
			return '';

		$href = sprintf('cmd=add_attr_form&%s',$this->url_base);
		$layout = '<td class="icon"><img src="%s/%s" alt="%s" /></td><td><a href="cmd.php?%s" title="%s" onclick="getDiv(\'ADD\').style.display = \'block\';return displayAJ(\'ADD\',\'%s\',\'%s\');">%s</a></td>';

		if (isAjaxEnabled())
			return sprintf($layout,IMGDIR,'add.png',_('Add'),
				htmlspecialchars($href),_('Add new attribute to this object'),
				htmlspecialchars($href),_('Add new attribute'),_('Add new attribute'));
		else
			return sprintf($this->layout['action'],IMGDIR,'add.png',_('Add'),
				htmlspecialchars($href),_('Add new attribute to this object'),_('Add new attribute'));
	}

	protected function getMenuItemShowChildren($children_count) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=query_engine&server_id=%s&filter=%s&base=%s&scope=one&query=none',
			$this->getServerID(),rawurlencode('objectClass=*'),rawurlencode($this->template->getDN()));

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'children.png',_('Children'),
				htmlspecialchars($href),_('View the children of this object'),
				htmlspecialchars($href),_('Loading'),
				($children_count == 1) ? _('View 1 child') : sprintf(_('View %s children'),$children_count));
		else
			return sprintf($this->layout['action'],IMGDIR,'children.png',_('Children'),
				htmlspecialchars($href),_('View the children of this object'),
				($children_count == 1) ? _('View 1 child') : sprintf(_('View %s children'),$children_count));
	}

	protected function getMenuItemExportSub() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=export_form&%s&scope=%s',$this->url_base,'sub');

		if (isAjaxEnabled())
			return sprintf($this->layout['actionajax'],IMGDIR,'save.png',_('Save'),
				htmlspecialchars($href),_('Save a dump of this object and all of its children'),
				htmlspecialchars($href),_('Loading'),_('Export subtree'));
		else
			return sprintf($this->layout['action'],IMGDIR,'save.png',_('Save'),
				htmlspecialchars($href),_('Save a dump of this object and all of its children'),_('Export subtree'));
	}

	/** CHOOSERS **/

	/**
	 * RDN Chooser
	 */
	protected function drawRDNChooser() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (! count($this->template->getRDNAttrs())) {
			printf('<tr><th colspan="2">%s</th></tr>','RDN');

			echo '<tr><td class="value" colspan="2"><center><select name="rdn_attribute[]" id="rdn_attribute">';
			printf('<option value="">%s</option>',_('select the rdn attribute'));

			if ($_SESSION[APPCONFIG]->getValue('appearance','rdn_all_attrs'))
				$allattrs = true;
			else
				$allattrs = false;

			foreach ($this->template->getAttributes($allattrs) as $attr) {
				$n = $attr->getName(false);

				if ($attr->getName() != 'objectclass') {
					$m = $attr->getFriendlyName();
					$b = '&nbsp;';
					printf('<option value="%s">%s%s(%s)</option>',$n,$m,$b,$n);
				}
			}

			echo '</select></center></td></tr>';

		} else {
			echo '<tr><td colspan="2">';
			foreach ($this->template->getRDNAttrs() as $rdn)
				printf('<input type="hidden" name="rdn_attribute[]" value="%s" id="rdn_attribute"/>',htmlspecialchars($rdn));
			echo '</td></tr>';
		}
	}

	/**
	 * Container Chooser
	 */
	protected function drawContainerChooser($default_container) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		echo '<tr>';
		printf('<td class="heading">%s</td>',_('Container'));
		printf('<td><input type="text" name="container" size="40" value="%s" />',htmlspecialchars($default_container));
		draw_chooser_link('entry_form.container');
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Object Class Chooser
	 */
	protected function drawObjectClassChooser() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$socs = $this->getServer()->SchemaObjectClasses();
		if (! $socs)
			$socs = array();

		echo '<tr>';
		printf('<td class="heading">%s</td>',_('ObjectClasses'));
		echo '<td><select name="new_values[objectclass][]" multiple="true" size="15">';

		foreach ($socs as $name => $oclass) {
			if (! strcasecmp('top',$name))
				continue;

			printf('<option %s value="%s">%s</option>',
				($oclass->getType() == 'structural') ? 'style="font-weight: bold" ' : '',
				htmlspecialchars($oclass->getName(false)),$oclass->getName(false));
		}

		echo '</select>';
		echo '</td>';
		echo '</tr>';

		if ($_SESSION[APPCONFIG]->getValue('appearance','show_hints')) {
			printf('<tr><td>&nbsp;</td><td><small><img src="%s/light.png" alt="Hint" /><span class="hint">',IMGDIR);
			echo _('Hint: You must choose exactly one structural objectClass (shown in bold above)');
			echo '</span></small><br /></td></tr>';
		}
	}

	/** INTERNAL ATTRIBUTES **/

	protected function drawInternalAttributes() {
		if ($this->template->getAttributesInternal())
			foreach ($this->template->getAttributesInternal() as $attribute)
				$this->draw('Internal',$attribute);
		else
			printf('<tr><td>(%s)<br/></td></tr>',_('No internal attributes'));

		echo "\n";
	}

	protected function drawInternalAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$this->draw('Template',$attribute);
	}

	/** FORM METHODS **/

	public function drawFormStart() {
		echo '<form action="cmd.php" method="post" enctype="multipart/form-data" name="entry_form" onSubmit="return submitForm(this)">';

		if ($_SESSION[APPCONFIG]->getValue('confirm','update'))
			echo '<input type="hidden" name="cmd" value="update_confirm" />';
		else
			echo '<input type="hidden" name="cmd" value="update" />';
	}

	protected function drawForm() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		printf('<input type="hidden" name="server_id" value="%s" />',$this->getServerID());
		printf('<input type="hidden" name="dn" value="%s" />',htmlspecialchars($this->template->getDN()));
		printf('<input type="hidden" name="template" value="%s" />',$this->template->getID());

		echo '<table class="entry" cellspacing="0" align="center" border=0>';

		$this->drawShownAttributes();
		$this->drawFormSubmitButton();

		echo '</table>';

		$this->drawHiddenAttributes();
	}

	public function drawFormEnd() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		echo '</form>';

		# Javascript
		$this->drawJavascript();

		# For debugging, show the template object.
		if (! $_SESSION[APPCONFIG]->getValue('appearance','hide_debug_info') && get_request('debug','GET')) {
			echo "\n\n";
			printf('<img src="%s/plus.png" alt="Plus" onClick="if (document.getElementById(\'DEBUGtemplate\').style.display == \'none\') { document.getElementById(\'DEBUGtemplate\').style.display = \'block\' } else { document.getElementById(\'DEBUGtemplate\').style.display = \'none\' };"/>',IMGDIR);
			echo '<div id="DEBUGtemplate" style="display: none">';
			echo '<fieldset>';
			printf('<legend>DEBUG: %s</legend>',$this->template->getDescription());
			echo '<textarea cols=120 rows=20>';
			debug_dump($this);
			echo '</textarea>';
			echo '</fieldset>';
			echo '</div>';
		}
	}

	public function drawFormSubmitButton() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (! $this->template->isReadOnly())
			printf('<tr><td colspan=2><center><input type="submit" id="create_button" name="submit" value="%s" /></center></td></tr>',_('Update Object'));
	}

	/** STEP FORM METHODS **/

	private function drawStepTitle($page) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);
		if (DEBUGTMP||DEBUGTMPSUB) printf('<font size=-2>* %s [templateNAME:%s]</font><br />',__METHOD__,$this->template->getName());

		# The default template needs to ask the user for objectClasses.
		if ($this->template->isType('default')) {
			# The default template only uses 2 pages
			$this->pagelast = 2;

			echo '<center><h4>';
			printf('%s: ',sprintf(_('Step %s of %s'),$page,$this->pagelast));

			if ($page == 1)
				echo _('Container and ObjectClass(es)');
			else
				echo _('Specify attributes and values');

			echo '</h4></center>';

		} elseif ($this->template->getDescription())
			printf('<center><h4>%s (%s)</h4></center>',
				_($this->template->getDescription()),
				sprintf(_('Step %s of %s'),$page,$this->pagelast));
	}

	private function drawStepFormStart($page) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (($this->template->isType('default') && $this->template->getContext() == 'create' && $page == 1) || $page < $this->pagelast) {
			echo '<form action="cmd.php?cmd=template_engine" method="post" enctype="multipart/form-data" name="entry_form" onSubmit="return submitForm(this)">';

		} else {
			echo '<form action="cmd.php" method="post" enctype="multipart/form-data" name="entry_form" onSubmit="return submitForm(this)">';

			if ($_SESSION[APPCONFIG]->getValue('confirm','create'))
				echo '<input type="hidden" name="cmd" value="create_confirm" />';
			else
				echo '<input type="hidden" name="cmd" value="create" />';
		}
	}

	protected function drawStepForm($page) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		printf('<input type="hidden" name="server_id" value="%s" />',$this->getServerID());
		printf('<input type="hidden" name="template" value="%s" />',$this->template->getID());
		printf('<input type="hidden" name="page" value="%s" />',$page+1);

		$this->drawHiddenAttributes();

		if ($this->template->isType('default') && $page == 1) {
				echo '<table class="forminput" align="center" border=0>';

				$this->drawContainerChooser($this->template->getContainer());
				$this->drawObjectClassChooser();

		} else {
			printf('<input type="hidden" name="container" value="%s" />',htmlspecialchars($this->template->getContainer()));

			echo '<table class="entry" cellspacing="0" align="center" border=0>';

			$this->drawRDNChooser();

			if ($this->template->isType('default') && $this->template->getContext() == 'create')
				$this->drawStepFormDefaultAttributes();
			else
				$this->drawShownAttributes();
		}

		$this->drawStepFormSubmitButton($page);

		echo '</table>';
	}

	private function drawStepFormEnd() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$this->drawFormEnd();
	}

	private function drawStepFormSubmitButton($page) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		echo '<tr>';
		if ($page < $this->pagelast)
			printf('<td>&nbsp;</td><td><input type="submit" id="create_button" value="%s" /></td>',_('Proceed &gt;&gt;'));
		else
			printf('<td><center><input type="submit" id="create_button" name="submit" value="%s"/></center></td>',_('Create Object'));
		echo '</tr>';
	}

	/**
	 * Given our known objectClass in the template, this will render the required MAY and optional MUST attributes
	 */
	private function drawStepFormDefaultAttributes() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		# Put required attributes first
		$attrs = array();
		$attrs['required'] = array();
		$attrs['optional'] = array();
		foreach ($this->template->getAttributes(true) as $attribute) {
			# Skip the objectclass attribute, we already know it in a default creation form.
			if ($attribute->getName() == 'objectclass')
				continue;

			if ($attribute->isRequired())
				array_push($attrs['required'],$attribute);

			elseif (! $attribute->getValues())
				array_push($attrs['optional'],$attribute);
		}

		printf('<tr><th colspan="2">%s</th></tr>',_('Required Attributes'));
		if (count($attrs['required']))
			foreach ($attrs['required'] as $attribute)
				$this->draw('Template',$attribute);

		else
			printf('<tr class="noinput"><td colspan="2"><center>(%s)</center></td></tr>',_('none'));

		printf('<tr><th colspan="2">%s</th></tr>',_('Optional Attributes'));
		if (count($attrs['optional']))
			foreach ($attrs['optional'] as $attribute)
				$this->draw('Template',$attribute);

		else
			printf('<tr class="noinput"><td colspan="2"><center>(%s)</center></td></tr>',_('none'));

		echo "\n";
	}

	/** DRAW ATTRIBUTES **/

	private function drawShownAttributes() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		foreach ($this->template->getAttributesShown() as $attribute)
			if (($attribute->getPage() == $this->page) && ($attribute->isRequired() || $attribute->isMay())) {
				$this->draw('Template',$attribute);
				echo "\n";
			}
	}

	/** DRAW PAGE JAVACRIPT */

	protected function drawJavascript() {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		echo "\n";
		printf('<!-- START: %s -->',__METHOD__);
		echo "\n";

		printf('<script type="text/javascript" language="javascript" src="%stemplaterender.js"></script>',JSDIR);
		echo "\n";

		printf('<!-- START: MAIN FORM VALIDATION: %s -->',__METHOD__);
		echo '
<script type="text/javascript" language="javascript">
function validateForm(silence) {
	var i = 0;
	var valid = true;
	var components = null;'."\n";

		foreach ($this->template->getAttributes(true) as $attribute) {
			if ($attribute->isVisible() && ($attribute->getOnchange()) || $attribute->isRequired()) {
				echo '
	components = getAttributeComponents("new","'.$attribute->getName().'");
	for (i = 0; i < components.length; i++) {
		if (window.validate_'.$attribute->getName().') {
			valid = (!validate_'.$attribute->getName().'(components[i],silence) || !valid) ? false : true;
		}
	}';
				echo "\n";
			}
		}

		# If we displayed the RDN chooser...
		if (! count($this->template->getRDNAttrs()))
			echo '       valid = (!document.getElementById(\'rdn_attribute\').value || !valid) ? false : true;';

		echo '
	return valid;
}
</script>';
		echo "\n";
		printf('<!-- END: MAIN FORM VALIDATION: %s -->',__METHOD__);
		echo "\n";

		$this->drawTemplateJavascript();

		# If we have a DateAttribute, we need to set some defaults for the js_calendar.
		if (isset($_SESSION[APPCONFIG])) {
			foreach ($this->template->getAttributesShown() as $attribute) {
				if (array_key_exists($attribute->getName(),array_change_key_case($_SESSION[APPCONFIG]->getValue('appearance','date_attrs')))) {
					echo '<!-- START: GLOBAL SETTINGS FOR THE js_calendar -->'."\n";
					echo '<script type="text/javascript" language="javascript">'."\n";
					echo 'var defaults = new Array();'."\n";
					printf('var default_date_format = "%s";',$_SESSION[APPCONFIG]->getValue('appearance','date'));
					echo "\n";
					echo '</script>'."\n";
					echo '<!-- END: GLOBAL SETTINGS FOR THE js_calendar -->'."\n";
					echo "\n";

					break;
				}
			}
		}

		foreach ($this->template->getAttributesShown() as $attribute)
			$this->draw('Javascript',$attribute);

		echo '<script type="text/javascript" language="javascript">validateForm(true);</script>'."\n";
		printf('<!-- END: %s -->',__METHOD__);
		echo "\n";
	}

	/**
	 * Javascript Functions
	 */
	private function drawTemplateJavascript() {
		printf('<!-- START: ONCHANGE PROCESSING %s -->',__METHOD__);
		echo "\n";
		foreach ($this->template->getAttributes(true) as $attribute)
			if ($onchange = $attribute->getOnChange())
				if (is_array($onchange))
					foreach ($onchange as $value)
						$this->template->OnChangeAdd($attribute->getName(),$value);
				else
					$this->template->OnChangeAdd($attribute->getName(),$onchange);
		printf('<!-- END: ONCHANGE PROCESSING %s -->',__METHOD__);
		echo "\n";

		printf('<!-- START: %s -->',__METHOD__);

		echo '
<script type="text/javascript" language="javascript">
var attrTrace;
function fill(id,value) {
	attrTrace = new Array();
	fillRec(id,value);
}
function fillRec(id,value) {
	if (attrTrace[id] == 1) {
		return;
	} else {
		var pre = "";
		var suf = "";
		var i;
		attrTrace[id] = 1;

		pla_setComponentValue(pla_getComponentById(id),value);

		// here comes template-specific implementation,generated by php
		if (false) {}';

		foreach ($this->template->getAttributes(true) as $attribute) {
			if ($attribute->isVisible() && ($attribute->getOnchange()) || $attribute->isRequired()) {
				$attr = $attribute->getName();

				printf("
		else if ((i = id.indexOf('_%s_')) >= 0) {",$attr);
				echo "
			pre = id.substring(0,i+1);";
				printf("
			suf = id.substring(i + 1 + '%s'.length,id.length);\n",$attr);

				$this->draw('FillJavascript',$attribute,'id','value');

				if (isset($attribute->js['autoFill']))
					echo $attribute->js['autoFill'];

				echo "
	}\n";
			}
		}

		echo '}
}
</script>';
		echo "\n";
		printf('<!-- END: %s -->',__METHOD__);
		echo "\n";
	}

	/** ATTRIBUTE TITLE **/

	protected function drawTitleAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (($this->template->getContext() == 'edit')
			&& ($attribute->hasBeenModified() || in_array($attribute->getName(),get_request('modified_attrs','REQUEST',false,array()))))
			echo '<tr class="updated">';
		else
			echo '<tr>';

		echo '<td class="title">';
		$this->draw('Name',$attribute);
		echo '</td>';

		echo '<td class="note">';

		# Setup the $attr_note, which will be displayed to the right of the attr name (if any)
		if ($_SESSION[APPCONFIG]->getValue('appearance','show_attribute_notes'))
			$this->draw('Notes',$attribute);

		echo '</td>';
		echo '</tr>';
	}

	/** ATTRIBUTE LINE **/

	protected function drawStartValueLineAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (($this->template->getContext() == 'edit')
			&& ($attribute->hasBeenModified() || in_array($attribute->getName(),get_request('modified_attrs','REQUEST',false,array()))))
			echo '<tr class="updated">';
		else
			echo '<tr>';

		echo '<td class="value" colspan=2>';
	}

	protected function drawEndValueLineAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		echo '</td>';
		echo '</tr>';

		if ($attribute->getSpacer())
			echo '<tr class="spacer"><td colspan="3">&nbsp;</td></tr>';

		if (($this->template->getContext() == 'edit')
			&& ($attribute->hasBeenModified() || in_array($attribute->getName(),get_request('modified_attrs','REQUEST',false,array()))))
			echo '<tr class="updated"><td class="bottom" colspan="2"></td></tr>';
	}

	protected function drawTemplateAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$this->draw('Title',$attribute);
		$this->draw('TemplateValues',$attribute);
	}

	protected function drawTemplateValuesAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s:%s</font><br />',time(),__METHOD__);

		$this->draw('StartValueLine',$attribute);

		# Draws values
		$value_count = $attribute->getValueCount();
		for ($i=0;$i<$value_count;$i++)
			$this->draw('Value',$attribute,$i);

		if (! $attribute->isInternal()) {
			$blankvalue_count = $attribute->getMaxValueCount();
			if ($blankvalue_count < 0)
				$blankvalue_count = 1;

			$blankvalue_count -= $value_count;

			for ($j=0;$j<$blankvalue_count;$j++)
				$this->draw('Value',$attribute,$i+$j);

			if (($value_count == $blankvalue_count) || ($value_count && $blankvalue_count < 1))
				$this->draw('Menu',$attribute);
		}

		$this->draw('EndValueLine',$attribute);
		echo "\n";
	}

	/** DRAW ICONS FOR ATTRIBUTES VALUES **/

	protected function drawIconAttribute($attribute,$val) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (is_dn_string($val) || $this->getServer()->isDNAttr($attribute->getName()))
			$this->draw('DnValueIcon',$attribute,$val);
		elseif (is_mail_string($val))
			$this->draw('MailValueIcon',$attribute,$val);
		elseif (is_url_string($val))
			$this->draw('UrlValueIcon',$attribute,$val);

		else {
			$icon = $attribute->getIcon();

			if ($icon)
				printf('<img src="%s" alt="Icon" align="top" />&nbsp;',$icon);
		}
	}

	protected function drawDnValueIconAttribute($attribute,$val) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (strlen($val) <= 0)
			printf('<img src="%s/go.png" alt="Go" align="top" />&nbsp;',IMGDIR);
		elseif ($this->getServer()->dnExists($val))
			printf('<a href="cmd.php?cmd=template_engine&amp;server_id=%s&amp;dn=%s" title="%s %s"><img src="%s/go.png" alt="Go" /></a>&nbsp;',
				$this->getServerID(),rawurlencode($val),_('Go to'),$val,IMGDIR);
		else
			printf('<a title="%s %s"><img src="%s/nogo.png" alt="Go" /></a>&nbsp;',_('DN not available'),$val,IMGDIR);
	}

	protected function drawMailValueIconAttribute($attribute,$val) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$img = sprintf('<img src="%s/mail.png" alt="%s" align="top" />',IMGDIR,_('Mail'));
		if (strlen($val) <= 0)
			echo $img;
		else
			printf('<a href="mailto:%s">%s</a>',htmlspecialchars($val),$img);
		echo '&nbsp;';
	}

	protected function drawUrlValueIconAttribute($attribute,$val) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$img = sprintf('<img src="%s/dc.png" alt="%s" align="top" />',IMGDIR,_('URL'));
		$url = explode(' +',$val,2);

		if (strlen($val) <= 0)
			echo $img;
		else
			printf('<a href="%s" target="new">%s</a>',htmlspecialchars($url[0]),$img);
		echo '&nbsp;';
	}

	/** DEFAULT ATTRIBUTE RENDERING **/

	/** javacript */

	protected function drawJavascriptAttribute($attribute) {
		if (! $attribute->needJS()) {
			printf('<!-- NO JS REQUIRED FOR %s -->',$attribute->getName());
			echo "\n";
			return;
		}

		printf('<!-- START: ATTRIBUTE %s (%s)-->',__METHOD__,$attribute->getName());
		echo "\n";

		echo '<script type="text/javascript" language="javascript">'."\n";

		echo '// focus'."\n";
		if ($attribute->needJS('focus')) {
			printf('function focus_%s(component) {',$attribute->getName());
			echo "\n";
			$this->draw('FocusJavascript',$attribute,'component');
			echo "};\n";
		}

		echo '// blur'."\n";
		if ($attribute->needJS('blur')) {
			printf('function blur_%s(component) {',$attribute->getName());
			echo "\n";
			$this->draw('BlurJavascript',$attribute,'component');
			echo "};\n";
		}

		echo '// validate'."\n";
		if ($attribute->needJS('validate')) {
			printf('function validate_%s(component,silence) {',$attribute->getName());
			echo "\n";
			echo '	var valid = true;';
			echo "\n";
			$this->draw('ValidateJavascript',$attribute,'component','silence','valid');
			echo "\n";
			echo '	if (valid) { component.style.backgroundColor = "white"; component.style.color = "black"; }';
			echo '	else { component.style.backgroundColor = \'#FFFFA0\'; component.style.color = "black"; }';
			echo '	return valid;';
			echo '}'."\n";
		}

		echo '</script>'."\n";

		printf('<!-- END: ATTRIBUTE %s (%s)-->',__METHOD__,$attribute->getName());
		echo "\n";
	}

	protected function getFocusJavascriptAttribute($attribute,$component) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		return '';
	}

	protected function getBlurJavascriptAttribute($attribute,$component) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$j = "\t".'fill('.$component.'.id,pla_getComponentValue('.$component.'));'."\n";
		$j .= "\t".'validate_'.$attribute->getName().'('.$component.',false);'."\n";

		return $j;
	}

	protected function drawFocusJavascriptAttribute($attribute,$component) {
		echo $this->get('FocusJavascript',$attribute,$component);
	}

	protected function drawBlurJavascriptAttribute($attribute,$component) {
		echo $this->get('BlurJavascript',$attribute,$component);
	}

	protected function drawFillJavascriptAttribute($attribute,$component_id,$component_value) {
		if ($attribute->needJS('validate'))
			printf("\tvalidate_%s(pla_getComponentById(%s),false);\n",$attribute->getName(),$component_id);
	}

	protected function drawValidateJavascriptAttribute($attribute,$component,$silence,$var_valid) {
		printf('var vals = getAttributeValues("new","%s");',$attribute->getName());
		echo 'if (vals.length <= 0) {';
		printf('%s = false;',$var_valid);
		printf('alertError("%s: %s",%s);',_('This attribute is required'),$attribute->getFriendlyName(),$silence);
		echo '}';
		echo "\n";

		printf('var comp = getAttributeComponents("new","%s");',$attribute->getName());
		echo 'for (var i = 0; i < comp.length; i++) {';
		printf('comp[i].style.backgroundColor = "%s";',$var_valid ? 'white' : '#FFFFA0');
		echo '}';
	}

	/** ATTRIBUTE MENU **/

	protected function drawMenuAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$item = '';

		echo '<table class="entry" border=0><tr><td width=25>&nbsp;</td>';
		echo '<td>';
		foreach (array('add','modify','rename') as $action)
			if ($item = $this->get('MenuItem',$attribute,$action))
				printf('<div class="add_value">%s</div>',$item);
		echo '</td>';
		echo '</tr></table>';
	}

	protected function getMenuItemAttribute($attribute,$action) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		# If there is no DN, then this is a creating entry.
		if (($this->template->getContext() == 'create') || $this->template->isReadOnly())
			return false;

		switch ($action) {
			case 'add':
				if ($attribute->isVisible() && ! $attribute->isReadOnly()
					&& $_SESSION[APPCONFIG]->isCommandAvailable('attribute_add_value')) {

					if ($attribute->haveMoreValues())
						return $this->get('AddValueMenuItem',$attribute);
				}

				return '';

			case 'modify':
				if (in_array($attribute->getName(),arrayLower($_SESSION[APPCONFIG]->getValue('modify_member','groupattr')))) {
					if ($attribute->isVisible() && ! $attribute->isReadOnly() && ! $attribute->isRDN()
						&& ($_SESSION[APPCONFIG]->isCommandAvailable('attribute_add_value')
						|| $_SESSION[APPCONFIG]->isCommandAvailable('attribute_delete_value')))
						return $this->get('ModifyMemberMenuItem',$attribute);
				}

				return '';

			case 'rename':
				if ($attribute->isVisible() && $attribute->isRDN() && ! $attribute->isReadOnly()
					&& $_SESSION[APPCONFIG]->isCommandAvailable('entry_rename'))
					return $this->get('RenameMenuItem',$attribute);

				return '';

			default:
				return false;
		}
	}

	protected function getAddValueMenuItemAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href_parm = htmlspecialchars(sprintf('cmd=add_value_form&server_id=%s&dn=%s&attr=%s',
			$this->getServerID(),rawurlencode($this->template->getDN()),rawurlencode($attribute->getName(false))));

		if (isAjaxEnabled())
			return sprintf('(<a href="cmd.php?%s" title="%s %s" onclick="return displayAJ(\'ADDVALUE%s\',\'%s&amp;raw=1\',\'%s\');">%s</a>)',
				$href_parm,_('Add an additional value to attribute'),$attribute->getName(false),$attribute->getName(),
				$href_parm,_('Add Value to Attribute'),_('add value'));
		else
			return sprintf('(<a href="cmd.php?%s" title="%s %s">%s</a>)',
				$href_parm,_('Add an additional value to attribute'),$attribute->getName(false),_('add value'));
	}

	protected function getModifyMemberMenuItemAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd=modify_member_form&server_id=%s&dn=%s&attr=%s',
			$this->getServerID(),rawurlencode($this->template->getDN()),rawurlencode($attribute->getName()));

		if (isAjaxEnabled())
			return sprintf('(<a href="cmd.php?%s" title="%s: %s" onclick="return displayAJ(\'BODY\',\'%s\',\'%s\');">%s</a>)',
				htmlspecialchars($href),_('Modify members for'),$this->template->getDN(),
				htmlspecialchars($href),_('Modify group membership'),
				_('modify group members'));
		else
			return sprintf('(<a href="cmd.php?%s" title="%s: %s">%s</a>)',
				htmlspecialchars($href),_('Modify members for'),$this->template->getDN(),_('modify group members'));
	}

	protected function getRenameMenuItemAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$href = sprintf('cmd.php?cmd=rename_form&server_id=%s&dn=%s&template=%s',
			$this->getServerID(),rawurlencode($this->template->getDN()),$this->template->getID());

		return sprintf('<small>(<a href="%s">%s</a>)</small>',htmlspecialchars($href),_('rename'));
	}

	/** values **/

	protected function drawValueAttribute($attribute,$i) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);
		if (DEBUG_ENABLED)
			debug_log('Entered with (%s,%d)',1,__FILE__,__LINE__,__METHOD__,$attribute->getName(),$i);

		$val = $attribute->getValue($i);

		if ($attribute->isVisible()) {
			echo '<table cellspacing="0" cellpadding="0" width="100%" border=0><tr><td class="icon" width=25>';
			$this->draw('Icon',$attribute,$val);
			echo '</td>';

			echo '<td valign="top">';
		}

		if ($attribute->isInternal())
			$this->draw('FormReadOnlyValue',$attribute,$i);
		else
			$this->draw('FormValue',$attribute,$i);

		if ($attribute->isVisible()) {
			echo '</td>';

			echo '<td valign="top" align="right">';
			$this->draw('RequiredSymbol',$attribute);
			echo '</td></tr></table>';
		}
		echo "\n";
	}

	# @todo for userPasswords, we need to capture the default value of select lists, without specifying <default>
	protected function drawHelperAttribute($attribute,$i) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$params = $attribute->getHelper();

		# We take the first <id> only
		$id = isset($params['id']) ? $params['id'] : '';
		if (is_array($id)) {
			system_message(array(
				'title'=>_('Too many arguments'),
				'body'=>sprintf('%s (<b>%s</b>)',_('Helper attribute has too many ID values, only the first one is used'),count($id)),
				'type'=>'warn'));

			$id = $id[0];
		}

		# We take the first <display> only
		$display = isset($params['display']) ? $params['display'] : '';
		if (is_array($display)) {
			system_message(array(
				'title'=>_('Too many arguments'),
				'body'=>sprintf('%s (<b>%s</b>)',_('Helper attribute has too many DISPLAY values, only the first one is used'),count($display)),
				'type'=>'warn'));

			$display = $display[0];
		}

		# We take the first <default> only
		$default = isset($params['default']) ? $params['default'] : '';
		if (is_array($default)) {
			system_message(array(
				'title'=>_('Too many arguments'),
				'body'=>sprintf('%s (<b>%s</b>)',_('Helper attribute has too many DISPLAY values, only the first one is used'),count($default)),
				'type'=>'warn'));

			$default = $default[0];
		}

		if ($attribute->getHelperValue())
			$vals = $this->get('Default',$attribute,
				$this->template->getContainer() ? $this->template->getContainer() : $this->getServer()->getContainerPath($this->template->getDN()),
				'helpervalue');
		else
			$vals = isset($params['value']) ? $params['value'] : '';

		if ($this->template->getContext() == 'create')
			$dn = $this->template->getContainer();
		else
			$dn = $this->template->getDN();

		if (is_array($vals) && count($vals) > 0) {
			$found = false;

			printf('<select name="%s[%s][%s]" id="%s_%s_%s">',
				$id,htmlspecialchars($attribute->getName()),$i,
				$id,htmlspecialchars($attribute->getName()),$i);

			foreach ($vals as $v) {
				printf('<option value="%s" %s>%s</option>',$v,($v == $default) ? 'selected' : '',$v);

				if ($v == $default)
					$found = true;
			}

			if (! $found)
				printf('<option value="%s" selected>%s</option>',$default,$default ? $default : '&nbsp;');

			echo '</select>';

		} else {
			# Vals must be an empty array.
			if (is_array($vals))
				$vals = '';

			printf('<input type="text" name="%s[%s][%s]" id="%s_%s_%s" value="%s" size="4" />',
				$id,htmlspecialchars($attribute->getName()),$i,
				$id,htmlspecialchars($attribute->getName()),$i,
				htmlspecialchars($vals));
		}

		if ($display) {
			echo '<div class="helper">';
			printf('<span class="hint">%s</span>',$display);
			echo '</div>';
		}
	}

	protected function drawRequiredSymbolAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if ($attribute->isRequired() && ! $attribute->isReadOnly())
			echo '*';
	}

	/** BINARY ATTRIBUTE RENDERING **/

	#@todo do we need a $this->drawJavascriptAttribute($attribute) here too ?
	protected function drawJavascriptBinaryAttribute($attribute) {
		# If there are no values, then this javascript doesnt need to be drawn.
		if (! $attribute->getValues())
			return;

		static $drawn = false;

		# This JS may have been rendered by multiple Binary attributes
		if ($drawn)
			return;
		else
			$drawn = true;

		printf('<!-- START: BINARY ATTRIBUTE %s (%s)-->',__METHOD__,$attribute->getName());
		echo "\n";

		echo '<!-- This form is submitted by JavaScript when the user clicks "Delete attribute" on a binary attribute -->';
		echo '<form name="delete_attribute_form" action="cmd.php?cmd=delete_attr" method="post">';
		printf('<input type="hidden" name="server_id" value="%s" />',$this->getServerID());
		printf('<input type="hidden" name="dn" value="%s" />',htmlspecialchars($this->template->getDN()));
		printf('<input type="hidden" name="template" value="%s" />',$this->template->getID());
		echo '<input type="hidden" name="attr" value="FILLED IN BY JAVASCRIPT" />';
		echo '<input type="hidden" name="index" value="FILLED IN BY JAVASCRIPT" />';
		echo '</form>';

		echo '
<script type="text/javascript" language="javascript">
function deleteAttribute(attrName,friendlyName,i)
{
	if (confirm("'._('Really delete value from attribute').' \'" + friendlyName + "\'?")) {
		document.delete_attribute_form.attr.value = attrName;
		document.delete_attribute_form.index.value = i;
		document.delete_attribute_form.submit();
	}
}
</script>';
		echo "\n";

		printf('<!-- END: BINARY ATTRIBUTE %s (%s)-->',__METHOD__,$attribute->getName());
		echo "\n";
	}

	/** DATE ATTRIBUTE RENDERING **/

	protected function drawJavaScriptDateAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		printf('<!-- START: DATE ATTRIBUTE %s (%s)-->',__METHOD__,$attribute->getName());
		echo "\n";

		$this->drawJavaScriptAttribute($attribute);

		static $drawn = false;

		# This JS may have been rendered by multiple Date attributes
		if (! $drawn) {
			printf('<script type="text/javascript" language="javascript" src="%sjscalendar/lang/calendar-en.js"></script>',JSDIR);
			printf('<script type="text/javascript" language="javascript" src="%sjscalendar/calendar-setup.js"></script>',JSDIR);
			printf('<script type="text/javascript" language="javascript" src="%sdate_selector.js"></script>',JSDIR);

			$drawn = true;
		}

		$config = array();
		$config['date'] = array_change_key_case($_SESSION[APPCONFIG]->getValue('appearance','date_attrs'));
		$config['time'] = array_change_key_case($_SESSION[APPCONFIG]->getValue('appearance','date_attrs_showtime'));
		$config['format'] = $_SESSION[APPCONFIG]->getValue('appearance','date');

		if (isset($config['date'][$attribute->getName()]))
			$config['format'] = $config['date'][$attribute->getName()];

		for ($i=0;$i<=$attribute->getValueCount();$i++) {
			printf('<script type="text/javascript" language="javascript">defaults[\'f_date_%s_%s\'] = \'%s\';</script>',$attribute->getName(),$i,$config['format']);

			if (in_array_ignore_case($attribute->getName(),array_keys($config['time'])) && ($config['time'][$attribute->getName()]))
				printf('<script type="text/javascript" language="javascript">defaults[\'f_time_%s_%s\'] = \'%s\';</script>',$attribute->getName(),$i,'true');

			echo "\n";
		}

		printf('<!-- END: DATE ATTRIBUTE %s (%s)-->',__METHOD__,$attribute->getName());
		echo "\n";
	}

	/**
	 * Draws an HTML date selector button which, when clicked, pops up a date selector dialog.
	 */
	protected function drawSelectorPopupDateAttribute($attribute,$i) {
		printf('<a href="javascript:dateSelector(\'%s_%s\');" title="%s"><img class="chooser" src="%s/calendar.png" id="f_trigger_%s_%s" style="cursor: pointer;" alt="Calendar" /></a>',
			$attribute->getName(),$i,_('Click to popup a dialog to select a date graphically'),IMGDIR,$attribute->getName(),$i);
	}

	/** DN ATTRIBUTES **/

	protected function drawIconDnAttribute($attribute,$val) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		$this->draw('DnValueIcon',$attribute,$val);
	}

	/** OBJECT CLASS ATTRIBUTE **/

	protected function drawIconObjectClassAttribute($attribute,$val) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (strlen($val) > 0) {
			$href = sprintf('cmd.php?cmd=schema&server_id=%s&view=objectclasses&viewvalue=%s',
				$this->getServerID(),$val);
			printf('<a href="%s" title="%s"><img src="%s/info.png" alt="Info" /></a>&nbsp;',
				htmlspecialchars($href),_('View the schema description for this objectClass'),IMGDIR);
		}
	}

	/** PASSWORD ATTRIBUTES **/

	protected function drawJavascriptPasswordAttribute($attribute) {
		static $drawn = false;

		# This JS may have been rendered by multiple Binary attributes
		if ($drawn)
			return;
		else
			$drawn = true;

		printf('<!-- START: PASSWORD ATTRIBUTE %s (%s)-->',__METHOD__,$attribute->getName());
		echo "\n";

		$this->drawJavascriptAttribute($attribute);

		# Add the javascript so we can call check password later.
		echo '
<script type="text/javascript" language="javascript">
	function passwordComparePopup(component_id) {
		mywindow = open(\'password_checker.php\',\'myname\',\'resizable=no,width=500,height=200,scrollbars=1\');
		mywindow.location.href = \'password_checker.php?componentid=\'+component_id;
		if (mywindow.opener == null) mywindow.opener = self;
	}
</script>';
		echo "\n";

		printf('<!-- END: PASSWORD ATTRIBUTE %s (%s)-->',__METHOD__,$attribute->getName());
		echo "\n";
	}

	protected function drawCheckLinkPasswordAttribute($attribute,$component_id) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		printf('<small><a href="javascript:passwordComparePopup(\'%s\')">%s...</a></small><br />',
			$component_id,_('Check password'));
	}

	/** RANDOM PASSWORD **/

	/**
	 * This will draw the javascript that displays to the user the random password generated
	 *
	 * @todo This function doesnt work well if there are more than 1 RandomPasswordAttributes on the form for the same attribute (unlikely situation)
	 */
	protected function drawJavascriptRandomPasswordAttribute($attribute) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		printf("\n<!-- START: %s -->\n",__METHOD__);
		$this->drawJavascriptPasswordAttribute($attribute);

		$pwd = password_generate();
		$pwd = str_replace("\\","\\\\",$pwd);
		$pwd = str_replace("'","\\'",$pwd);

		printf("\n<!-- %s -->\n",__METHOD__);
		echo '<script type="text/javascript" language="javascript">'."\n";
		echo 'var i = 0;'."\n";
		printf('var component = document.getElementById(\'new_values_%s_\'+i);',$attribute->getName());
		echo "\n";
		echo 'while (component) {'."\n";
		echo '	if (!component.value) {'."\n";
		printf('		component.value = \'%s\';',$pwd);
		echo "\n";
		printf('		alert(\'%s:\n%s\');',_('A random password was generated for you'),$pwd);
		echo "\n";
		echo '	};'."\n";
		echo '	i++;'."\n";
		printf('	component = document.getElementById(\'new_values_%s_\'+i);',$attribute->getName());
		echo "\n";
		# It seems that JS gets stuck in a loop if there isnt a command here? - normally this alert isnt shown.
		printf('alert("It seems another element was found, PLA hasnt been configured for this situation Component: "+component.value+" I:"+i);',$attribute->getName());
		echo "\n";
		echo '}'."\n";
		echo '</script>';
		printf("\n<!-- END: %s -->\n",__METHOD__);
	}

	protected function drawDefaultHelperPasswordAttribute($attribute,$i) {
		$id = 'enc';

		if ($val = $attribute->getValue($i))
			$default = get_enc_type($val);
		else
			$default = $this->getServer()->getValue('appearance','password_hash');

		if (! $attribute->getPostValue())
			printf('<input type="hidden" name="post_value[%s][]" value="%s"/>',$attribute->getName(),$i);

		printf('<select name="%s[%s][%s]" id="%s_%s_%s">',
			$id,htmlspecialchars($attribute->getName()),$i,
			$id,htmlspecialchars($attribute->getName()),$i);

		foreach (password_types() as $v)
			printf('<option value="%s" %s>%s</option>',$v,($v == $default) ? 'selected' : '',$v);

		echo '</select>';
	}

	/** SELECTION ATTRIBUTE RENDERING **/

	protected function drawIconSelectionAttribute($attribute,$val) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		if (! $attribute->isMultiple() || $attribute->isReadOnly())
			$this->drawIconAttribute($attribute,$val);
	}

	protected function getMenuItemSelectionAttribute($attribute,$i) {
		if (DEBUGTMP) printf('<font size=-2>%s</font><br />',__METHOD__);

		switch ($i) {
			case 'add':
				if (! $attribute->isMultiple())
					return $this->getMenuItemAttribute($attribute,$i);
				else
					return '';

			case 'modify':
				return '';

			default:
				return $this->getMenuItemAttribute($attribute,$i);
		}
	}
}
?>
