<?php


# Class to manage hermes mail aliases
class aliases extends frontControllerApplication
{
	# Hermes details
	private $retrievalUsername = 'hermes';	// Username for retrieval of the file by Hermes at <baseUrl>/{$this->domain}.txt
	private $retrievalSystem = 'Hermes';	// Commonly-known name of the retriever
	
	# Regexp for localparts
	#!# Ideally need to get rid of ._ in the aliases
	private $allowedAliasRegexp = '^([a-z0-9-._]+):(.+)';
	
	
	# Function to assign defaults additional to the general application defaults
	public function defaults ()
	{
		# Specify available arguments as defaults or as NULL (to represent a required argument)
		$defaults = array (
			'applicationName' => 'E-mail alias management',
			'div' => 'aliases',
			'database' => __CLASS__,
			'table' => false,
			'username' => __CLASS__,
			'password' => NULL,
			'apiUsername' => false,		// Optional API access
			'administrators' => true,
			'authentication' => true,	// Users are set at container (Apache) level, but this flag requires all parts of this web application also to require a user to be supplied
			'authFileGroup' => 'editors',
			'lists' => array (
				'general' => 'General addresses',
			),
			'uneditable' => array (),	// Files which are automatically generated and should be locked-off from the interface, listed as 'domain/section' => http://domain.cam.ac.uk/path/to/whereitcanbeedited.html
			'tabUlClass' => 'tabsflat',
		);
		
		# Return the defaults
		return $defaults;
	}
	
	
	# Function to assign supported actions
	public function actions ()
	{
		# Define available tasks
		$actions = array (
			'domain' => array (
				'description' => 'Domain control panel',
				'url' => 'domain.html',
				'tab' => 'Domain home',
				'icon' => 'application_home',
			),
			'sources' => array (
				'description' => 'View/edit individual alias list',
				'url' => 'sources.html',
				'tab' => 'View/edit by group',
				'icon' => 'application_edit',
			),
			'all' => array (
				'description' => 'All aliases',
				'url' => 'all.html',
				'tab' => 'View complete list',
				'icon' => 'application_view_list',
			),
			'domainhome' => array (
				'description' => false,
				'url' => '',
				'validateDomain' => true,
			),
			'domainsources' => array (
				'description' => 'View/edit individual alias list for this domain',
				'url' => 'sources/',
				'validateDomain' => true,
			),
			'domainall' => array (
				'description' => 'All aliases for this domain',
				'url' => 'all/',
				'validateDomain' => true,
			),
			'data' => array (	// Used for e.g. AJAX calls, etc.
				'description' => 'Data point',
				'url' => 'data.html',
				'export' => true,
				'validateDomain' => true,
			),
			'update' => array (
				'description' => 'Edit alias list',
				'url' => '/%1/edit',
				'usetab' => 'sources',
				'validateDomain' => true,
			),
			'export' => array (
				'description' => 'Export',
				'export' => true,
				'authentication' => false,
				'validateDomain' => true,
			),
		);
		
		# Assemble the list of additional users, for use with authFileGroup
		$additionalEditors = array ();
		foreach ($this->settings['domains'] as $domain => $editors) {
			$additionalEditors = array_merge ($additionalEditors, $editors);
		}
		$this->authFileGroupAdditionalUsers = array_unique ($additionalEditors);
		
		# Add in Administrators to the user list for each domain
		foreach ($this->settings['domains'] as $domain => $editors) {
			$this->settings['domains'][$domain] = array_unique (array_merge (array_keys ($this->administrators), $editors));
		}
		
		# Remove domains which the user cannot access; NB Ideally this would be done in main() or equivalent, but it is needed by domainDroplist
		$this->settings['domainsOriginalCached'] = $this->settings['domains'];
		$this->settings['domains'] = $this->getDomainsOfUser ($this->user);
		
		# For the home tab, if there is more than one domain, disable the link and replace with a droplist instead
		if ($domainDroplist = $this->domainDroplist ()) {
			$actions['home']['url'] = false;
			$actions['home']['tab'] = $domainDroplist;
		}
		
		# Return the actions
		return $actions;
	}
	
	
	# Database structure definition
	public function databaseStructure ()
	{
		return "
			CREATE TABLE IF NOT EXISTS `administrators` (
			  `username` varchar(191) NOT NULL COMMENT 'Username' PRIMARY KEY,
			  `active` enum('','Yes','No') NOT NULL DEFAULT 'Yes' COMMENT 'Currently active?'
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Aliases administrators'
		;";
	}
	
	
	# Function to get the domains of a user
	private function getDomainsOfUser ($username)
	{
		# Filter domains
		$domains = $this->settings['domainsOriginalCached'];
		foreach ($domains as $domain => $users) {
			if (!in_array ($username, $users)) {
				unset ($domains[$domain]);
			}
		}
		
		# Return the modified array
		return $domains;
	}
	
	
	
	# Additional processing
	protected function main ()
	{
		# Start a cookie session (used for for making the tabs 'sticky' and for confirmation of successful changes)
		if (!$this->exportType) {
			if (!session_id ()) {
				ini_set ('session.cookie_secure', 1);	// Enable cookies in HTTPS mode
				session_start ();
			}
		}
		
		# Define the root of the system on the disk, where the alias files are located
		$this->fileRoot = $_SERVER['DOCUMENT_ROOT'] . $this->baseUrl . '/';
		
		# Organise the domains alphabetically
		ksort ($this->settings['domains']);
		
		# Validate the domain if required, or end (which will also prevent the page itself running)
		$this->domain = false;
		if (isSet ($this->actions[$this->action]['validateDomain']) && $this->actions[$this->action]['validateDomain']) {
			if (!$this->domain = $this->domainValid ()) {return false;}
			
			# Now that the user has reached a valid domain, set it in a cookie
			if (!$this->exportType) {
				$_SESSION['domain'] = $this->domain;
			}
		}
		
		# Create file listings if there is a domain
		$this->files = array ();
		$this->uneditableFiles = array ();
		if ($this->domain) {
			
			# Get the list of files, omitting any that cannot be read
			$directory = $this->fileRoot . $this->domain . '/';
			if (!$files = directories::listFiles ($directory, $supportedFileTypes = array ('txt'), $directoryIsFromRoot = true, $skipUnreadableFiles = true)) {
				echo "\n<p class=\"error\">There are currently no files for this domain. Please <a href=\"{$this->baseUrl}/feedback.html\">contact the Webmaster</a> to request that one be added.</p>";
				return false;
			}
			
			# Convert to a listing including descriptions
			foreach ($files as $file => $attributes) {
				$name = $attributes['name'];
				$this->files[$name] = $this->getFileDescription ($name);
			}
			asort ($this->files);
			
			# Remove 'lastretrieved'
			if (isSet ($this->files['lastretrieved'])) {
				unset ($this->files['lastretrieved']);
			}
			
			# Create a listing of uneditable files
			foreach ($this->files as $source => $name) {
				$lookFor = $this->domain . '/' . $source;
				if (isSet ($this->settings['uneditable'][$lookFor])) {
					$this->uneditableFiles[$source] = $this->settings['uneditable'][$lookFor];
				}
			}
		}
	}
	
	
	# Function to create a domain droplist
	private function domainDroplist ()
	{
		# If there is are no domains or only one domain accessible to this user, return false to signal not to create a droplist
		if (count ($this->settings['domains']) < 2) {return false;}
		
		# Create the list
		$values = array ();
		$values["{$this->baseUrl}/"] = 'Select domain...';
		ksort ($this->settings['domains']);
		foreach ($this->settings['domains'] as $domain => $users) {
			$location = "{$this->baseUrl}/{$domain}/";
			$values[$location] = $domain;
		}
		
		# Assign the selected item (which will be ignored if the value is bogus)
		$selected = (isSet ($_GET['domain']) ? "{$this->baseUrl}/{$_GET['domain']}/" : "{$this->baseUrl}/");
		
		# Create the jumplist and add a processor
		$introductoryText = 'Select domain:';
		$html = application::htmlJumplist ($values, $selected, $this->baseUrl . '/', $name = 'domainselection', $parentTabLevel = 0, $class = 'jumplist', $introductoryText);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to check security
	private function domainValid ()
	{
		# If not on the home page, ensure that a domain is specified
		if (!isSet ($_GET['domain'])) {
			echo "\n<p class=\"warning\">No domain has been specified. Please check the webserver setup.</p>";
			return false;
		}
		
		# Ensure the domain is in the settings
		$requestedDomain = $_GET['domain'];
		if (!array_key_exists ($requestedDomain, $this->settings['domainsOriginalCached'])) {
			echo "\n<p class=\"warning\">There is no such domain.</p>";
			return false;
		}
		
		# Ensure the user has rights, except for exported files (which have their own security)
		if (!$this->exportType) {
			$users = $this->settings['domains'][$requestedDomain];
			if (!in_array ($this->user, $users)) {
				echo "\n<p class=\"warning\">You do not have rights to amend this domain. If you think you should do, please <a href=\"{$this->baseUrl}/feedback.html\">contact the Webmaster</a>.</p>";
				return false;
			}
		}
		
		# Signal that all tests have been passed by returning the name of the domain
		return $requestedDomain;
	}
	
	
	# Function to get the description of each list (which is in the first line of each file)
	private function getFileDescription ($name)
	{
		# Define the file
		$file = $this->fileRoot . $this->domain . '/' . $name . '.txt';
		
		# Read the contents
		$lines = file ($file);
		$description = $lines[0];
		
		# Substitute name if no contents
		if (!preg_match ('/^## (.+)$/', $description, $matches)) {
			$description = $name;
		}
		
		# Trim #/space characters from both ends
		$description = trim ($description, '# ');
		$description = trim ($description);
		
		# Return the contents
		return htmlspecialchars ($description);
	}
	
	
	# Home page
	public function home ()
	{
		# Welcome
		$html  = "\n<h2>Welcome</h2>";
		$html .= "\n<p>This system lets authorised users edit @<em>&lt;domain&gt;</em>.cam.ac.uk aliases.</p>";
		$html .= "\n<p>Central systems will periodically update the list by retrieving it from this system.</p>";
		$html .= $this->selectDomain ();
		
		# Unset any cookie
		if (isSet ($_SESSION['domain'])) {
			unset ($_SESSION['domain']);
		}
		
		# Show the HTML
		echo $html;
	}
	
	
	# Domain selection list
	private function selectDomain ($function = false)
	{
		# Determine the function part of the URL
		$functionUrlSlug = (($function && $function != 'domain') ? "{$function}/" : '');
		
		# Redirect to what the user will perceive as the 'current' domain if they were on a domain page
		if ($function) {
			if (isSet ($_SESSION['domain'])) {
				$redirectTo = "{$_SERVER['_SITE_URL']}{$this->baseUrl}/{$_SESSION['domain']}/{$functionUrlSlug}";
				unset ($_SESSION['domain']);
				echo application::sendHeader (301, $redirectTo, true);
				return;
			}
		}
		
		# If there is only one domain, end at this point
		if (count ($this->settings['domains']) == 1) {
			foreach ($this->settings['domains'] as $domain => $users) {
				$onlyDomain = $domain;
			}
			$redirectTo = "{$_SERVER['_SITE_URL']}{$this->baseUrl}/{$onlyDomain}";
			echo application::sendHeader (301, $redirectTo, true);
			return;
		}
		
		# Create a list of domains
		$domains = array ();
		foreach ($this->settings['domains'] as $domain => $users) {
			$domains[] = "<a href=\"{$this->baseUrl}/{$domain}/{$functionUrlSlug}\">{$domain}</a>";
		}
		
		# Compile the HTML
		$html  = "\n<p>Please select which domain:</p>";
		$html .= application::htmlUl ($domains);
		
		# Return the HTML
		return $html;
	}
	
	
	# Tab/redirection page for domain home
	public function domain ()
	{
		# Show the list
		echo $this->selectDomain (__FUNCTION__);
	}
	
	
	# Tab/redirection page for sources
	public function sources ()
	{
		# Show the list
		echo $this->selectDomain (__FUNCTION__);
	}
	
	
	# Tab/redirection page for all
	public function all ()
	{
		# Show the list
		echo $this->selectDomain (__FUNCTION__);
	}
	
	
	# Front domain page
	public function domainhome ()
	{
		# Compile the HTML
		$html  = "\n<h2>@{$this->domain} Control panel</h2>";
		$html .= "\n<p>You can <a href=\"{$this->baseUrl}/{$this->domain}/all/\"><strong>view the automatically-compiled master list</strong></a>, or edit the individual sources:</p>";
		$html .= $this->sourcesTable ();
		$html .= "\n<h2 id=\"individual\">Edit individual alias</h2>";
		$html .= $this->editIndividualAlias ();
		$html .= "\n<h2>Last retrieval by {$this->retrievalSystem}</h2>";
		$html .= $this->lastRetrievalTime ();
		$html .= "\n<h2>@{$this->domain} Managers</h2>";
		$html .= "\n<p>The managers of @{$this->domain} are: <strong>" . implode ('</strong>, <strong>', $this->settings['domains'][$this->domain]) . "</strong>.</p>";
		$html .= "\n<p>Please <a href=\"{$this->baseUrl}/feedback.html\">contact the Webmaster</a> to have this list changed.</p>";
		
		# Show the HTML
		echo $html;
	}
	
	
	# Form for searching for, and then editing, an individual alias
	public function editIndividualAlias ()
	{
		# Start the HTML
		$html  = '';
		
		# Define the name of the search form (stage 1 form)
		$searchFormName = 'search';
		$editFormName = 'editalias';
		$aliasFormElement = 'alias';
		
		# Determine the state
		$searchFormPosted	= (isSet ($_POST[$searchFormName]) && isSet ($_POST[$searchFormName][$aliasFormElement]));
		$editFormPosted		= (isSet ($_POST[$editFormName])   && isSet ($_POST[$editFormName][$aliasFormElement]));
		
		# If the first form has not been posted, show it
		if (!$searchFormPosted && !$editFormPosted) {
			
			# Define the data URL
			$dataUrl = "{$_SERVER['_SITE_URL']}{$this->baseUrl}/{$this->domain}/data.html";
			
			# Run the form module
			$form = new form (array (
				'displayRestrictions' => false,
				'name' => $searchFormName,
				'nullText' => false,
				'div' => 'ultimateform',
				'display' => 'template',
				'displayTemplate' => '{[[PROBLEMS]]}<p>Alias: &nbsp; {alias} {[[SUBMIT]]}</p>',
				'submitButtonText' => 'Go!',
				'submitButtonAccesskey' => false,
				'formCompleteText' => false,
				'requiredFieldIndicator' => false,
				'reappear' => true,
				'submitTo' => './#individual',
			));
			$form->search (array (
				'name'			=> $aliasFormElement,
				'title'			=> 'Alias',
				'size' => 30,
				'required' => true,
				'autocomplete'	=> $dataUrl,
				'autocompleteOptions' => array ('delay' => 0, ),
				'autofocus' => true,
			));
			if (!$result = $form->process ($html)) {return $html;}
		}
		
		# Assign the alias, from either form
		$alias = ($editFormPosted ? $_POST[$editFormName][$aliasFormElement] : $_POST[$searchFormName][$aliasFormElement]);
		
		# Ensure this is a valid alias
		$localParts = $this->getLocalParts ();
		if (!application::iin_array ($alias, $localParts, NULL, $alias /* returned by reference */)) {
			$html  = "\n<p>The alias <em>" . htmlspecialchars ($alias) . "</em> does not exist. Please <a href=\"{$this->baseUrl}/{$this->domain}/\">search again</a>.</p>";
			return $html;
		}
		
		# Get the details of the selected alias
		if (!$aliasDetails = $this->getAliasDetails ($alias)) {
			$html  = "\n<p>There was a problem retrieving the details for <em>" . htmlspecialchars ($alias) . '</em>.</p>';
			#!# Report error to admin - this should not happen
			return $html;
		}
		
		# Pre-compile a link and name for the source
		$sourceLink = "{$this->baseUrl}/{$this->domain}/sources/{$aliasDetails['source']}/";
		$sourceName = $this->files[$aliasDetails['source']];
		
		# Ensure that an uneditable file cannot be edited this way
		if (isSet ($this->uneditableFiles[$aliasDetails['source']])) {
			$html  = "\n<p>This alias is part of the <a href=\"{$sourceLink}\">" . htmlspecialchars ($sourceName) . "</a> grouping which cannot be edited via this form but is <a href=\"{$this->uneditableFiles[$aliasDetails['source']]}\">set externally</a>.</p>";
			return $html;
		}
		
		# Show the editing form for this alias
		$form = new form (array (
			'displayRestrictions' => false,
			'name' => $editFormName,
			'nullText' => false,
			'div' => 'ultimateform',
			'display' => 'template',
			'displayTemplate' => "{[[PROBLEMS]]}<p>{alias}: &nbsp; {value} {[[SUBMIT]]} &nbsp; <span class=\"smaller\">or <a href=\"{$this->baseUrl}/{$this->domain}/\">cancel</a></span>.</p>",
			'submitButtonText' => 'Update!',
			'submitButtonAccesskey' => false,
			'formCompleteText' => false,
			'requiredFieldIndicator' => false,
		));
		$form->input (array (
			'name'			=> $aliasFormElement,
			'title'			=> 'Alias',
			'required' => true,
			'editable' => false,
			'default' => $alias,
		));
		$form->email (array (
			'name'			=> 'value',
			'title'			=> 'Recipients',
			'size' => 60,
			'required' => true,
			'multiple' => true,
			'default' => $aliasDetails['value'],
		));
		if (!$result = $form->process ($html)) {return $html;}
		
		# Update the file
		$this->updateSingleAlias ($aliasDetails['source'], $alias, $result['value']);
		
		# Confirm success
		$html = "\n<p>{$this->tick} The alias <em>" . htmlspecialchars ($alias) . "</em> has been updated, in the source <a href=\"{$sourceLink}\">" . htmlspecialchars ($sourceName) . "</a>. Do you wish to <a href=\"{$this->baseUrl}/{$this->domain}/\">edit another</a>?</p>";
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to determine which file an alias is in
	private function getAliasDetails ($alias)
	{
		# Find the alias and return its data
		foreach ($this->files as $source => $description) {
			$aliases = $this->getAliasesAsKeyValue ($source);
			if (isSet ($aliases[$alias])) {
				return array (
					'key'		=> $alias,
					'value'		=> trim ($aliases[$alias]),
					'source'	=> $source,
				);
			}
		}
		
		# Return empty array
		return array ();
	}
	
	
	# Function to update an individual alias
	private function updateSingleAlias ($source, $alias, $newValue)
	{
		# Open the current source file
		$aliases = $this->getAliases ($source);
		
		# Replace the current one
		$newLine = "{$alias}: {$newValue}";
		$aliases = preg_replace ("/^{$alias}\s*:.+$/m", $newLine, $aliases);
		
		# Write the new file
		$result = $this->updateAliases ($source, $aliases);
		
		# Return the result
		return $result;
	}
	
	
	# Export the aliases
	public function export ()
	{
		# Ensure there is a username
		if (!isSet ($_SERVER['REMOTE_USER']) || $_SERVER['REMOTE_USER'] != $this->retrievalUsername) {
			application::sendHeader (401);	// 401 Unauthorized
			return false;
		}
		
		# Compile the files into the master list
		$contents = $this->compileMasterList ();
		
		# Save the last retrieval time
		$this->lastRetrievalTime ($write = true);
		
		# Echo the contents, as plain text
		header ('Content-Type: text/plain');
		echo $contents;
	}
	
	
	# Function to read and write the last retrieval time
	public function lastRetrievalTime ($write = false)
	{
		# Determine the filename
		$filename = $this->fileRoot . $this->domain . '/' . 'lastretrieved.txt';
		
		# Update if required
		if ($write) {
			file_put_contents ($filename, time ());
		}
		
		# Read the date in the file
		if (!is_readable ($filename)) {
			return "\n<p>The time that {$this->retrievalSystem} last retrieved the file could not be determined.</p>";
		}
		
		# Obtain the time
		$string = file_get_contents ($filename);
		$time = date ('g.ia, j/M/Y', trim ($string));
		
		# Return the time
		return "\n<p>{$this->retrievalSystem} last retrieved the aliases for @{$this->domain} at <strong>{$time}</strong>.</p>";
	}
	
	
	# All aliases for a domain
	public function domainall ()
	{
		# Compile the files into the master list
		$contents = $this->compileMasterList ();
		
		# Compile the HTML
		$html  = "\n<p>Below is the compiled, master list, which will get picked up by {$this->retrievalSystem}.</p>";
		$html .= $this->lastRetrievalTime ();
		$html .= $this->aliasesToHtml ($contents);
		
		# Show the HTML
		echo $html;
	}
	
	
	# Get the local part of each alias line
	private function getLocalParts ($exclude = false)
	{
		# Get each list and extract the local parts
		$localParts = array ();
		foreach ($this->files as $source => $name) {
			
			# Skip one if required
			if ($exclude && ($exclude == $source)) {continue;}
			
			# Do the matching
			$aliases = $this->getAliases ($source);
			preg_match_all ('/' . $this->allowedAliasRegexp . '/m', $aliases, $matches);	// /m flag is multi-line
			
			# Add to the list
			$localParts = array_merge ($localParts, $matches[1]);
		}
		
		# Unique the list (this should not be necessary)
		$localParts = array_unique ($localParts);
		
//echo count ($localParts);
		
		# Return the list
		return $localParts;
	}
	
	
	# Get the aliases in a file as key=>value
	private function getAliasesAsKeyValue ($source)
	{
		# Get the aliases
		$aliasesText = $this->getAliases ($source);
		
		# Do match
		preg_match_all ('/' . $this->allowedAliasRegexp . '/m', $aliasesText, $matches, PREG_SET_ORDER);	// /m flag is multi-line
		
		# Loop through each
		$aliases = array ();
		foreach ($matches as $alias) {
			$key	= $alias[1];
			$value	= $alias[2];
			$aliases[$key] = $value;
		}
		
		# Return the list
		return $aliases;
	}
	
	
	
	# Function to compile all the alias lists into a master list
	private function compileMasterList ()
	{
		# Compile the list
		$contents  = "## IMPORTANT: Do not edit this automatically-generated file.\n";
		$contents .= "## Instead, edit its constituent parts at {$_SERVER['_SITE_URL']}{$this->baseUrl}/{$this->domain}/\n";
		$contents .= "## \n";
		foreach ($this->files as $source => $name) {
			$contents .= "## \n";
			$contents .= "## Aliases for: {$name} (from {$source}.txt) :\n";
			$contents .= "## \n";
			$contents .= $this->getAliases ($source) . "\n";
			$contents .= "## \n";
			$contents .= "## End of aliases for: {$name}\n";
			$contents .= "## \n";
		}
		$contents .= "## \n";
		$contents .= "## END OF FILE";
		
		# Return the list
		return $contents;
	}
	
	
	# Sources page for a domain
	public function domainsources ($source)
	{
		# Check for a named source
		if ($source && (isSet ($this->files[$source]))) {
			$html = $this->showSource ($source);
		} else {
			$html = $this->listSources ();
		}
		
		# Show the HTML
		echo $html;
	}
	
	
	# Function to list the sources
	private function listSources ()
	{
		# Obtain the HTML
		$html  = "\n<p>The aliases are arranged into logical groupings, each of which can be edited below.</p>";
		$html .= "\n<p>To add a new logical grouping, please <a href=\"{$this->baseUrl}/feedback.html\">contact the Webmaster</a> via the feedback form.</p>";
		$html .= $this->sourcesTable ();
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to create a table of sources
	private function sourcesTable ()
	{
		# Create links to each source
		$table = array ();
		foreach ($this->files as $source => $description) {
			$table[$source] = array (
				''		=> $description,
				'View'	=> "<a href=\"{$this->baseUrl}/{$this->domain}/sources/{$source}/\">View</a>",
				'Edit'	=> (isSet ($this->uneditableFiles[$source]) ? "<a href=\"{$this->uneditableFiles[$source]}\">[Edit data externally]</a>" : "<a href=\"{$this->baseUrl}/{$this->domain}/sources/{$source}/update/\">Update</a>"),
			);
		}
		
		# Compile the HTML
		$html  = application::htmlTable ($table, array (), $class = 'lines', $keyAsFirstColumn = false, false, $allowHtml = true, $showColons = true);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to list the sources
	public function showSource ($source)
	{
		# Define and check readability of the file
		if (!isSet ($this->files[$source])) {
			return "\n<p class=\"warning\">The selected alias list does not exist or could not be read.</p>";
		}
		
		# Confirm an update if present
		if (isSet ($_SESSION['updated'])) {
			echo "<div class=\"graybox\"><img src=\"/images/icons/tick.png\" alt=\"Tick\" class=\"icon\" /> The <strong>{$this->files[$source]}</strong> list has been updated, as below.</div>";
			unset ($_SESSION['updated']);
		}
		
		# Get the file
		$contents = $this->getAliases ($source);
		
		# Compile the HTML
		$html  = "<p>Below is the <strong>{$this->files[$source]}</strong> aliases list [<a href=\"{$this->baseUrl}/{$this->domain}/sources/{$source}/update/\">edit</a>]:</p>";
		$html .= $this->aliasesToHtml ($contents);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to get the contents of an aliases file
	private function getAliases ($source)
	{
		return file_get_contents ($this->fileRoot . $this->domain . '/' . $source . '.txt');
	}
	
	
	# Function to present an aliases list
	private function aliasesToHtml ($contents)
	{
		# Compile the list
		$html  = "\n<pre>";
		$html .= htmlspecialchars ($contents);
		$html .= "\n</pre>";
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to edit a list
	public function update ($source)
	{
		# Define and check readability of the file
		if (!isSet ($this->files[$source])) {
			echo "\n<p class=\"warning\">The selected alias list does not exist or could not be read.</p>";
			return false;
		}
		
		# Prevent editing of uneditable files
		if (isSet ($this->uneditableFiles[$source])) {
			echo "\n<p>The selected alias list cannot be edited via this interface.</p>";
			echo "\n<p>Instead, data is controlled by <a href=\"{$this->uneditableFiles[$source]}\">the system which generates the list</a> directly.</p>";
			return false;
		}
		
		# Get the file
		$contents = $this->getAliases ($source);
		
		# Form for editing
		$form = new form (array (
			'display' => 'paragraphs',
			'formCompleteText' => false,
			'unsavedDataProtection' => true,
		));
		$form->heading ('p', "Format:<pre># Comment lines start with a hash\nalias: address1@cam.ac.uk, address2@hotmail.com\nanother-alias: foobar@{$this->domain}.cam.ac.uk, spqr1@cam.ac.uk</pre>");
		$form->textarea (array (
			'name'			=> 'aliases',
			'title'			=> "Edit the <strong>{$this->files[$source]}</strong> list, then press submit below",
			'required'		=> true,
			'cols'			=> 125,
			'rows'			=> 35,
			'default'		=> $contents,
		));
		
		# Validate the format
		if ($unfinalisedData = $form->getUnfinalisedData ()) {
			if ($unfinalisedData['aliases']) {
				
				# Get the existing aliases, except for the current file's current (pre-editing) aliases
				$existingAliases = $this->getLocalParts ($source);
				
				# Explode by line and check each one
				$lines = explode ("\n", $unfinalisedData['aliases']);
				$errorLines = array ();
				$invalidAddressLines = array ();
				$localParts = array ();
				foreach ($lines as $index => $line) {
					$lineNumberHuman = $index + 1;
					$line = trim ($line);
					
					# Skip blank lines and comment lines
					if (!strlen ($line)) {continue;}
					if (substr ($line, 0, 1) == '#') {continue;}
					
					# Ensure that each alias starts correctly
					if (!preg_match ('/' . $this->allowedAliasRegexp . '/', $line, $matches)) {
						$errorLines[$lineNumberHuman] = $line;
						continue;
					}
					
					# Check the e-mail addresses are correctly-formatted
					$addresses = preg_split ("/\s*,\s*/", trim ($matches[2]), NULL);
					foreach ($addresses as $address) {
						if (!strlen ($address) || !application::validEmail ($address)) {
							$invalidAddressLines[$lineNumberHuman] = $matches[2];
							continue 2;	// Continue to next $line
						}
					}
					
					# Capture the local parts for later checking; note that ones whose syntax doesn't match will not get registered here, but they will be caught with another error
					$localParts[] = $matches[1];
				}
				
				# State syntax error lines
				if ($errorLines) {
					$form->registerProblem ('syntaxerror', 'Wrong format in lines: <strong>' . implode ('</strong>, <strong>', array_keys ($errorLines)) . '</strong>:' . application::dumpData ($errorLines, false, true));
				}
				
				# State invalid e-mail address lines
				if ($invalidAddressLines) {
					#!# Ideally this should show the actual alias name, and not include 'DEBUG' at the start
					$form->registerProblem ('invalidaddress', 'Invalid e-mail address format found in lines: <strong>' . implode ('</strong>, <strong>', array_keys ($invalidAddressLines)) . '</strong>:' . application::dumpData ($invalidAddressLines, false, true));
				}
				
				# Check the local parts have not been duplicated internally within the block entered
				$uniqued = array_unique ($localParts);
				if (count ($uniqued) != count ($localParts)) {
					$duplicates = application::array_duplicate_values ($localParts);
					$form->registerProblem ('duplicated', 'You have specified the following aliases more than once: <strong>' . implode ('</strong>, <strong>', $duplicates) . '</strong>');
				}
				
				# Check that the local parts have not been duplicated with existing aliases elsewhere
				if ($preexisting = array_intersect ($existingAliases, $uniqued)) {
					$form->registerProblem ('existing', 'The following aliases already exist in another alias list: <strong>' . implode ('</strong>, <strong>', $preexisting) . '</strong>');
				}
			}
		}
		
		# Process the form
		if (!$result = $form->process ($html)) {return false;}
		
		# Update the file
		if (!$this->updateAliases ($source, $result['aliases'], $error /* returned by reference */)) {
			application::utf8Mail ($this->settings['administratorEmail'], 'Aliases system: error', wordwrap ($error), "From: {$this->settings['administratorEmail']}");
			echo "<p class=\"warning\">{$this->cross} Apologies, an error occured while updating the aliases list. The Webmaster has been notified of the problem..</p>";
			echo "<p class=\"warning\">The error was: <br /><tt>" . htmlspecialchars ($error) . '</tt></p>';
			return false;
		}
		
		# Redirect the user automatically or give a link
		$_SESSION['updated'] = '1';
		$url = "{$this->baseUrl}/{$this->domain}/sources/{$source}/";
		application::sendHeader (302, $_SERVER['_SITE_URL'] . $url);
		echo "\n" . "<p><strong>Thanks for keeping the list updated.</strong><br />You can <a href=\"{$url}\">view the updated entry</a> or <a href=\"{$this->baseUrl}/{$this->domain}/sources/{$source}/update/\">edit it further</a>.</p>";
	}
	
	
	# Function to update an alias file
	private function updateAliases ($source, $text, &$error = false)
	{
		# Determine the filename for this source
		$filename = $this->fileRoot . $this->domain . '/' . $source . '.txt';
		
		# Back up the old file
		$directory = $this->fileRoot . $this->domain . '/' . 'backups/';
		if (!is_dir ($directory)) {
			mkdir ($directory);
		}
		if (!is_writable ($directory)) {
			$error = "The directory {$directory} is not writable.";
			return false;
		}
		$backupFile = $directory . $source . '.until-' . date ('Ymd-His') . ".replacedby-{$this->user}" . '.txt';
		if (!rename ($filename, $backupFile)) {
			$error = "The backup could not be created, by moving from {$filename} to {$backupFile}.";
			return false;
		}
		
		# Save the new file
		$result = file_put_contents ($filename, $text);
		if ($result === false) {
			$error = "The new file could not be written to {$filename}.";
			return false;
		}
		$result = ($result !== false);	// Deal with boolean false vs zero-bytes written, and return as bool
		
		# Return result
		return $result;
	}
	
	
	# Function to provide auto-complete functionality
	public function data ()
	{
		# End if no query
		if (!isSet ($_GET['term']) || !strlen ($_GET['term'])) {return false;}
		
		# Obtain the query
		$term = $_GET['term'];
		
		# Ensure the query term is valid
		$testAgainst = $term . ':bogus';	// Add a bogus value part, as $this->allowedAliasRegexp assumes that is present
		if (!preg_match ('/' . $this->allowedAliasRegexp . '/', $testAgainst)) {return false;}
		
		# Get the existing aliases
		$localParts = $this->getLocalParts ();
		sort ($localParts);
		
		# Match the aliases, using a word boundary match so that "student" would pick up e.g. "phd.students"
		$data = array ();
		foreach ($localParts as $localPart) {
			if (preg_match ("/\b{$term}/", $localPart)) {
				$data[] = array ('value' => $localPart, 'label' => $localPart);	// See: http://af-design.com/blog/2010/05/12/using-jquery-uis-autocomplete-to-populate-a-form/ which documents this
			}
		}
		
		# Arrange the data
		$json = json_encode ($data);
		
		# Send the text
		echo $json;
	}
	
	
	# API call for dashboard
	public function apiCall_dashboard ($username = NULL)
	{
		# Start the HTML
		$html = '';
		
		# State that the service is enabled
		$data['enabled'] = true;
		
		# Ensure a username is supplied
		if (!$username) {
			$data['error'] = 'No username was supplied.';
			return $data;
		}
		
		# Get the domains of the user
		$this->settings['domains'] = $this->getDomainsOfUser ($username);
		
		# End if not enabled
		if (!$this->settings['domains']) {
			$data['authorised'] = false;
			return $data;
		}
		
		# Define description
		$data['descriptionHtml'] = "<p>The aliases system lets you manage e-mail aliases.</p>";
		
		# Add direct access to domains lists
		$html .= $this->domainDroplist ();
		/*
		$list = array ();
		foreach ($this->settings['domains'] as $domain => $users) {
			$list[] = "<a href=\"{$this->baseUrl}/{$domain}/\">Edit {$domain} domain</a>";
		}
		$html .= application::htmlUl ($list);
		*/
		
		# Register the HTML
		$data['html'] = $html;
		
		# Return the data
		return $data;
	}
}

?>
