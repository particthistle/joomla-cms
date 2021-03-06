<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\User;

\defined('JPATH_PLATFORM') or die;

use Joomla\Authentication\Password\Argon2idHandler;
use Joomla\Authentication\Password\Argon2iHandler;
use Joomla\Authentication\Password\BCryptHandler;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Authentication\Password\ChainedHandler;
use Joomla\CMS\Authentication\Password\CheckIfRehashNeededHandlerInterface;
use Joomla\CMS\Authentication\Password\MD5Handler;
use Joomla\CMS\Authentication\Password\PHPassHandler;
use Joomla\CMS\Crypt\Crypt;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Object\CMSObject;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;

/**
 * Authorisation helper class, provides static methods to perform various tasks relevant
 * to the Joomla user and authorisation classes
 *
 * This class has influences and some method logic from the Horde Auth package
 *
 * @since  1.7.0
 */
abstract class UserHelper
{
	/**
	 * Constant defining the Argon2i password algorithm for use with password hashes
	 *
	 * Note: PHP's native `PASSWORD_ARGON2I` constant is not used as PHP may be compiled without this constant
	 *
	 * @var    string|integer
	 * @since  4.0.0
	 */
	const HASH_ARGON2I = 2;

	/**
	 * Constant defining the Argon2id password algorithm for use with password hashes
	 *
	 * Note: PHP's native `PASSWORD_ARGON2ID` constant is not used as PHP may be compiled without this constant
	 *
	 * @var    string|integer
	 * @since  4.0.0
	 */
	const HASH_ARGON2ID = 3;

	/**
	 * Constant defining the BCrypt password algorithm for use with password hashes
	 *
	 * @var    string|integer
	 * @since  4.0.0
	 */
	const HASH_BCRYPT = PASSWORD_BCRYPT;

	/**
	 * Constant defining the MD5 password algorithm for use with password hashes
	 *
	 * @var    integer
	 * @since  4.0.0
	 * @deprecated  5.0  Support for MD5 hashed passwords will be removed
	 */
	const HASH_MD5 = 100;

	/**
	 * Constant defining the PHPass password algorithm for use with password hashes
	 *
	 * @var    integer
	 * @since  4.0.0
	 * @deprecated  5.0  Support for PHPass hashed passwords will be removed
	 */
	const HASH_PHPASS = 101;

	/**
	 * Method to add a user to a group.
	 *
	 * @param   integer  $userId   The id of the user.
	 * @param   integer  $groupId  The id of the group.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.7.0
	 * @throws  \RuntimeException
	 */
	public static function addUserToGroup($userId, $groupId)
	{
		// Cast as integer until method is typehinted.
		$userId  = (int) $userId;
		$groupId = (int) $groupId;

		// Get the user object.
		$user = new User($userId);

		// Add the user to the group if necessary.
		if (!\in_array($groupId, $user->groups))
		{
			// Check whether the group exists.
			$db = Factory::getDbo();
			$query = $db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__usergroups'))
				->where($db->quoteName('id') . ' = :groupId')
				->bind(':groupId', $groupId, ParameterType::INTEGER);
			$db->setQuery($query);

			// If the group does not exist, return an exception.
			if ($db->loadResult() === null)
			{
				throw new \RuntimeException('Access Usergroup Invalid');
			}

			// Add the group data to the user object.
			$user->groups[$groupId] = $groupId;

			// Reindex the array for prepared statements binding
			$user->groups = array_values($user->groups);

			// Store the user object.
			$user->save();
		}

		// Set the group data for any preloaded user objects.
		$temp         = User::getInstance($userId);
		$temp->groups = $user->groups;

		if (Factory::getSession()->getId())
		{
			// Set the group data for the user object in the session.
			$temp = Factory::getUser();

			if ($temp->id == $userId)
			{
				$temp->groups = $user->groups;
			}
		}

		return true;
	}

	/**
	 * Method to get a list of groups a user is in.
	 *
	 * @param   integer  $userId  The id of the user.
	 *
	 * @return  array    List of groups
	 *
	 * @since   1.7.0
	 */
	public static function getUserGroups($userId)
	{
		// Get the user object.
		$user = User::getInstance((int) $userId);

		return $user->groups ?? array();
	}

	/**
	 * Method to remove a user from a group.
	 *
	 * @param   integer  $userId   The id of the user.
	 * @param   integer  $groupId  The id of the group.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.7.0
	 */
	public static function removeUserFromGroup($userId, $groupId)
	{
		// Get the user object.
		$user = User::getInstance((int) $userId);

		// Remove the user from the group if necessary.
		$key = array_search($groupId, $user->groups);

		if ($key !== false)
		{
			unset($user->groups[$key]);
			$user->groups = array_values($user->groups);

			// Store the user object.
			$user->save();
		}

		// Set the group data for any preloaded user objects.
		$temp = Factory::getUser((int) $userId);
		$temp->groups = $user->groups;

		// Set the group data for the user object in the session.
		$temp = Factory::getUser();

		if ($temp->id == $userId)
		{
			$temp->groups = $user->groups;
		}

		return true;
	}

	/**
	 * Method to set the groups for a user.
	 *
	 * @param   integer  $userId  The id of the user.
	 * @param   array    $groups  An array of group ids to put the user in.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.7.0
	 */
	public static function setUserGroups($userId, $groups)
	{
		// Get the user object.
		$user = User::getInstance((int) $userId);

		// Set the group ids.
		$groups = ArrayHelper::toInteger($groups);
		$user->groups = $groups;

		// Get the titles for the user groups.
		$db = Factory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title']))
			->from($db->quoteName('#__usergroups'))
			->whereIn($db->quoteName('id'), $user->groups);
		$db->setQuery($query);
		$results = $db->loadObjectList();

		// Set the titles for the user groups.
		for ($i = 0, $n = \count($results); $i < $n; $i++)
		{
			$user->groups[$results[$i]->id] = $results[$i]->id;
		}

		// Store the user object.
		$user->save();

		// Set the group data for any preloaded user objects.
		$temp = Factory::getUser((int) $userId);
		$temp->groups = $user->groups;

		if (Factory::getSession()->getId())
		{
			// Set the group data for the user object in the session.
			$temp = Factory::getUser();

			if ($temp->id == $userId)
			{
				$temp->groups = $user->groups;
			}
		}

		return true;
	}

	/**
	 * Gets the user profile information
	 *
	 * @param   integer  $userId  The id of the user.
	 *
	 * @return  object
	 *
	 * @since   1.7.0
	 */
	public static function getProfile($userId = 0)
	{
		if ($userId == 0)
		{
			$user   = Factory::getUser();
			$userId = $user->id;
		}

		// Get the dispatcher and load the user's plugins.
		PluginHelper::importPlugin('user');

		$data = new CMSObject;
		$data->id = $userId;

		// Trigger the data preparation event.
		Factory::getApplication()->triggerEvent('onContentPrepareData', array('com_users.profile', &$data));

		return $data;
	}

	/**
	 * Method to activate a user
	 *
	 * @param   string  $activation  Activation string
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.7.0
	 */
	public static function activateUser($activation)
	{
		$db       = Factory::getDbo();

		// Let's get the id of the user we want to activate
		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__users'))
			->where($db->quoteName('activation') . ' = :activation')
			->where($db->quoteName('block') . ' = 1')
			->where($db->quoteName('lastvisitDate') . ' IS NULL')
			->bind(':activation', $activation);
		$db->setQuery($query);
		$id = (int) $db->loadResult();

		// Is it a valid user to activate?
		if ($id)
		{
			$user = User::getInstance($id);

			$user->set('block', '0');
			$user->set('activation', '');

			// Time to take care of business.... store the user.
			if (!$user->save())
			{
				Log::add($user->getError(), Log::WARNING, 'jerror');

				return false;
			}
		}
		else
		{
			Log::add(Text::_('JLIB_USER_ERROR_UNABLE_TO_FIND_USER'), Log::WARNING, 'jerror');

			return false;
		}

		return true;
	}

	/**
	 * Returns userid if a user exists
	 *
	 * @param   string  $username  The username to search on.
	 *
	 * @return  integer  The user id or 0 if not found.
	 *
	 * @since   1.7.0
	 */
	public static function getUserId($username)
	{
		// Initialise some variables
		$db = Factory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__users'))
			->where($db->quoteName('username') . ' = :username')
			->bind(':username', $username)
			->setLimit(1);
		$db->setQuery($query);

		return $db->loadResult();
	}

	/**
	 * Hashes a password using the current encryption.
	 *
	 * @param   string          $password   The plaintext password to encrypt.
	 * @param   string|integer  $algorithm  The hashing algorithm to use, represented by `HASH_*` class constants, or a container service ID.
	 * @param   array           $options    The options for the algorithm to use.
	 *
	 * @return  string  The encrypted password.
	 *
	 * @since   3.2.1
	 * @throws  \InvalidArgumentException when the algorithm is not supported
	 */
	public static function hashPassword($password, $algorithm = self::HASH_BCRYPT, array $options = array())
	{
		$container = Factory::getContainer();

		// If the algorithm is a valid service ID, use that service to generate the hash
		if ($container->has($algorithm))
		{
			return $container->get($algorithm)->hashPassword($password, $options);
		}

		// Try a known handler next
		switch ($algorithm)
		{
			case self::HASH_ARGON2I :
				return $container->get(Argon2iHandler::class)->hashPassword($password, $options);

			case self::HASH_ARGON2ID :
				return $container->get(Argon2idHandler::class)->hashPassword($password, $options);

			case self::HASH_BCRYPT :
				return $container->get(BCryptHandler::class)->hashPassword($password, $options);

			case self::HASH_MD5 :
				return $container->get(MD5Handler::class)->hashPassword($password, $options);

			case self::HASH_PHPASS :
				return $container->get(PHPassHandler::class)->hashPassword($password, $options);
		}

		// Unsupported algorithm, sorry!
		throw new \InvalidArgumentException(sprintf('The %s algorithm is not supported for hashing passwords.', $algorithm));
	}

	/**
	 * Formats a password using the current encryption. If the user ID is given
	 * and the hash does not fit the current hashing algorithm, it automatically
	 * updates the hash.
	 *
	 * @param   string   $password  The plaintext password to check.
	 * @param   string   $hash      The hash to verify against.
	 * @param   integer  $user_id   ID of the user if the password hash should be updated
	 *
	 * @return  boolean  True if the password and hash match, false otherwise
	 *
	 * @since   3.2.1
	 */
	public static function verifyPassword($password, $hash, $user_id = 0)
	{
		$passwordAlgorithm = self::HASH_BCRYPT;
		$container         = Factory::getContainer();

		// Cheaply try to determine the algorithm in use otherwise fall back to the chained handler
		if (strpos($hash, '$P$') === 0)
		{
			/** @var PHPassHandler $handler */
			$handler = $container->get(PHPassHandler::class);
		}
		// Check for Argon2id hashes
		elseif (strpos($hash, '$argon2id') === 0)
		{
			/** @var Argon2idHandler $handler */
			$handler = $container->get(Argon2idHandler::class);

			$passwordAlgorithm = self::HASH_ARGON2ID;
		}
		// Check for Argon2i hashes
		elseif (strpos($hash, '$argon2i') === 0)
		{
			/** @var Argon2iHandler $handler */
			$handler = $container->get(Argon2iHandler::class);

			$passwordAlgorithm = self::HASH_ARGON2I;
		}
		// Check for bcrypt hashes
		elseif (strpos($hash, '$2') === 0)
		{
			/** @var BCryptHandler $handler */
			$handler = $container->get(BCryptHandler::class);
		}
		else
		{
			/** @var ChainedHandler $handler */
			$handler = $container->get(ChainedHandler::class);
		}

		$match  = $handler->validatePassword($password, $hash);
		$rehash = $handler instanceof CheckIfRehashNeededHandlerInterface ? $handler->checkIfRehashNeeded($hash) : false;

		// If we have a match and rehash = true, rehash the password with the current algorithm.
		if ((int) $user_id > 0 && $match && $rehash)
		{
			$user = new User($user_id);
			$user->password = static::hashPassword($password, $passwordAlgorithm);
			$user->save();
		}

		return $match;
	}

	/**
	 * Generate a random password
	 *
	 * @param   integer  $length  Length of the password to generate
	 *
	 * @return  string  Random Password
	 *
	 * @since   1.7.0
	 */
	public static function genRandomPassword($length = 8)
	{
		$salt = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$base = \strlen($salt);
		$makepass = '';

		/*
		 * Start with a cryptographic strength random string, then convert it to
		 * a string with the numeric base of the salt.
		 * Shift the base conversion on each character so the character
		 * distribution is even, and randomize the start shift so it's not
		 * predictable.
		 */
		$random = Crypt::genRandomBytes($length + 1);
		$shift = \ord($random[0]);

		for ($i = 1; $i <= $length; ++$i)
		{
			$makepass .= $salt[($shift + \ord($random[$i])) % $base];
			$shift += \ord($random[$i]);
		}

		return $makepass;
	}

	/**
	 * Method to get a hashed user agent string that does not include browser version.
	 * Used when frequent version changes cause problems.
	 *
	 * @return  string  A hashed user agent string with version replaced by 'abcd'
	 *
	 * @since   3.2
	 */
	public static function getShortHashedUserAgent()
	{
		$ua = Factory::getApplication()->client;
		$uaString = $ua->userAgent;
		$browserVersion = $ua->browserVersion;
		$uaShort = str_replace($browserVersion, 'abcd', $uaString);

		return md5(Uri::base() . $uaShort);
	}

	/**
	 * Check if there is a super user in the user ids.
	 *
	 * @param   array  $userIds  An array of user IDs on which to operate
	 *
	 * @return  boolean  True on success, false on failure
	 *
	 * @since   3.6.5
	 */
	public static function checkSuperUserInUsers(array $userIds)
	{
		foreach ($userIds as $userId)
		{
			foreach (static::getUserGroups($userId) as $userGroupId)
			{
				if (Access::checkGroup($userGroupId, 'core.admin'))
				{
					return true;
				}
			}
		}

		return false;
	}
}
