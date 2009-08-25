<?

	// Load in the settings
	if (!file_exists("conf.php"))
		die("Please copy conf.sample.php to conf.php and populate with your information.");
		
	require_once("conf.php");
	
	// Connect to the database.	 I'm using PostgreSQL, so you may need to replace this with a MySQL (or other DB) function.	 
	// If you do change it, edit the DB function calls below as well
	$conn_string	= "host={$db_host} port=5432 dbname={$db_name} user={$db_username} password={$db_password}";
	$db				= pg_connect($conn_string);
	
	// First, backup a users tweets
	$services[] = array(
		"db_table"	 => "twitter.{$twitter_username}_tweets",
		"api_url"	 => "http://twitter.com/statuses/user_timeline.json",
		"start_page" => "3" // Change to be the max page for your user. To find out #, adjust "page" var @ http://twitter.com/statuses/user_timeline.xml?count=200&page=3
	);

	// Second, backup the mentions (replies) to the user
	$services[] = array(
		"db_table"	 => "twitter.{$twitter_username}_mentions",
		"api_url"	 => "http://twitter.com/statuses/mentions.json",
		"start_page" => "2" // Change to be the max page for your user. To find out #, adjust "page" var @ http://twitter.com/statuses/mentions.xml?count=200&page=3
	);
	
	
	foreach ($services as $service)
	{
		// Create the table if it doesn't already exist
		$sql = "CREATE TABLE {$service['db_table']} (
			status_id				bigint	NOT NULL UNIQUE PRIMARY KEY,
			in_reply_to_status_id	bigint,
			text					text	NOT NULL,
			created_at				varchar NOT NULL,
			raw						text	NOT NULL,
			date_created			timestamp without time zone DEFAULT now() NOT NULL
		);";
		@pg_query($db, $sql);

		for($page=$service['start_page']; $page > 0; $page--)
		{
			$json = null; // Reset this value because PHP can be kinda stupid

			// Make the Twitter API call
			$cmd = "curl {$service['api_url']} -u {$twitter_username}:{$twitter_password} -d 'count=200' -d 'page={$page}' --get";

			echo "\n{$cmd}";
			exec($cmd, $json);
			$data = json_decode($json[0], true);
			echo "\nPage ({$page}) returned (".count($data).") tweets";

			// Reverse it so oldest first
			$data = array_reverse($data);

			// Loop through each one
			foreach($data as $tweet)
			{
				if ($tweet['in_reply_to_status_id'] < 1)
					$tweet['in_reply_to_status_id'] = "NULL";
					
				// Throw it in the DB, catching any errors
				$result = @pg_query("INSERT INTO {$service['db_table']} (status_id, in_reply_to_status_id, text, created_at, raw) VALUES (".($tweet['id']).", ".($tweet['in_reply_to_status_id']).", '".pg_escape_string($tweet['text'])."', '".pg_escape_string($tweet['created_at'])."', '".pg_escape_string(json_encode($tweet))."')");
				if (!$result) {
					echo "\nError inserting tweet_id ({$tweet['id']})";
				}
			}
		}		
	}
	
	pg_close();
?>