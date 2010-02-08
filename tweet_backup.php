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
		"start_page" => "2" // Change to be the max page for your user. To find out #, adjust "page" var @ http://twitter.com/statuses/user_timeline.xml?count=200&page=3
	);

	// Second, backup the mentions (replies) to the user
	$services[] = array(
		"db_table"	 => "twitter.{$twitter_username}_mentions",
		"api_url"	 => "http://twitter.com/statuses/mentions.json",
		"start_page" => "2" // Change to be the max page for your user. To find out #, adjust "page" var @ http://twitter.com/statuses/mentions.xml?count=200&page=3
	);

	// Third, backup the Direct Messages received by the user
	$services[] = array(
		"db_table"	 => "twitter.{$twitter_username}_dm_in",
		"api_url"	 => "http://twitter.com/direct_messages.json",
		"start_page" => "4" // Change to be the max page for your user. To find out #, adjust "page" var @ http://twitter.com/direct_messages.xml?count=200&page=3
	);

	// Fourth, backup the Direct Messages sent from the user
	$services[] = array(
		"db_table"	 => "twitter.{$twitter_username}_dm_out",
		"api_url"	 => "http://twitter.com/direct_messages/sent.json",
		"start_page" => "4" // Change to be the max page for your user. To find out #, adjust "page" var @ http://twitter.com/direct_messages/sent.xml?count=200&page=3
	);

	
	foreach ($services as $i => $service)
	{
		$tweet_count['added'][$i] = 0;
		$tweet_count['not_added'][$i] = 0;
		
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
			
			if (isset($json[0]))
			{
				$tweets = json_decode($json[0], true);
				echo "\nPage ({$page}) returned (".count($tweets).") tweets";

				// Reverse it so oldest first
				$tweets = array_reverse($tweets);

				// Loop through each one
				foreach($tweets as $tweet)
				{
					if ($tweet['in_reply_to_status_id'] < 1)
						$tweet['in_reply_to_status_id'] = "NULL";

					// Throw it in the DB, catching any errors
					$result = @pg_query("INSERT INTO {$service['db_table']} (status_id, in_reply_to_status_id, text, created_at, raw) VALUES (".($tweet['id']).", ".($tweet['in_reply_to_status_id']).", '".pg_escape_string($tweet['text'])."', '".pg_escape_string($tweet['created_at'])."', '".pg_escape_string(json_encode($tweet))."')");
					if (!$result) 
					{	
						$tweet_count['not_added'][$i]++;
						echo "\nError inserting tweet_id ({$tweet['id']})."; // It likely already exists in the DB
					}
					else 
					{
						$tweets_added['added'][$i]++;
						echo "\nAdded tweet_id ({$tweet['id']})";
					}
				}
			}
			else
			{
				die("\n\nError! " . $json);
			}
		}
	}
	
	//print_r($tweet_count);
	
	
	// Create the table if it doesn't already exist
	$sql = "CREATE TABLE twitter.{$twitter_username}_stats (
	    stat_id SERIAL NOT NULL UNIQUE PRIMARY KEY,
	    followers integer NOT NULL,
	    following integer NOT NULL,
	    updates integer NOT NULL,
	    date_created timestamp without time zone DEFAULT now() NOT NULL
	);";
	@pg_query($db, $sql);
	$cmd = "curl http://twitter.com/users/show/{$twitter_username}.json -u {$twitter_username}:{$twitter_password} --get";

	echo "\n{$cmd}";
	exec($cmd, $json);
	
	if (isset($json[0]))
	{
		$stats = json_decode($json[0], true);
		$result = @pg_query("INSERT INTO twitter.{$twitter_username}_stats (followers, following, updates) VALUES 
		(".($stats['followers_count']).", ".($stats['friends_count']).", '".($stats['statuses_count'])."')");
	}
	
	$result = @pg_query("SELECT count(*) as count FROM twitter.derek_tweets WHERE CAST(created_at as TIMESTAMP) > (NOW() - interval '24 hours')");
	if ($result) 
	{	
		$row = pg_fetch_row($result);
		$stats['tweet_count'] = $row[0];  
	}
	
	$result = @pg_query("SELECT count(*) as count FROM twitter.derek_mentions WHERE CAST(created_at as TIMESTAMP) > (NOW() - interval '24 hours')");
	if ($result) 
	{	
		$row = pg_fetch_row($result);
		$stats['mentions_count'] = $row[0];  
	}		
	
	$result = @pg_query("SELECT followers, following, updates FROM twitter.derek_stats ORDER BY date_created DESC LIMIT 2");
	if ($result) 
	{	
		$today = pg_fetch_row($result);
		$yesterday = pg_fetch_row($result);
		$stats['followers_gained'] = $today[0] - $yesterday[0];
		$stats['following_gained'] = $today[1] - $yesterday[1];
	}	
	
	mail($email, "Twitter stats", print_r($stats, true));
	
	pg_close();
?>
