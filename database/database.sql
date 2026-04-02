SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

CREATE TABLE `applications` (
  `appid` int(10) UNSIGNED NOT NULL COMMENT 'Primary Key',
  `name` varchar(255) NOT NULL COMMENT 'Application Name',
  `apikey` varchar(80) NOT NULL COMMENT 'Api Key',
  `apisecret` varchar(255) NOT NULL COMMENT 'Api Secret',
  `status` tinyint(3) UNSIGNED NOT NULL COMMENT '0: Inactive 1: active 2: deleted 3: Waiting ',
  `added` int(10) UNSIGNED NOT NULL COMMENT 'Create date (Unix Timestamp)',
  `description` text NOT NULL COMMENT 'Application Description',
  `organization` varchar(128) NOT NULL COMMENT 'Organization Name',
  `organizationurl` varchar(255) NOT NULL COMMENT 'Organization Website',
  `url` varchar(255) NOT NULL COMMENT 'Application Website',
  `apptype` tinyint(3) UNSIGNED NOT NULL COMMENT '0: Website 1: Mobile Device 2: Both',
  `accesstype` tinyint(3) UNSIGNED NOT NULL COMMENT '0: REST Api 1: oAuth 2',
  `apiversion` varchar(7) NOT NULL COMMENT 'Api Version',
  `scope` mediumblob NOT NULL COMMENT 'Application Scope',
  `public` tinyint(3) UNSIGNED NOT NULL COMMENT 'Is the application public in app directory',
  `callback` varchar(255) NOT NULL COMMENT 'Callback url for oAuth2',
  `owner` bigint(20) DEFAULT NULL COMMENT 'Owner User ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Applications that access our api';

-- --------------------------------------------------------

CREATE TABLE `bounces` (
  `bounceid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hash` char(64) NOT NULL COMMENT 'Email Address Hash (sha256)',
  `lastsoft` int(10) UNSIGNED NOT NULL,
  `lasthard` int(10) UNSIGNED NOT NULL,
  `hardbounces` int(10) UNSIGNED NOT NULL,
  `softbounces` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`bounceid`),
  UNIQUE KEY `hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Bounced Email addresses';

-- --------------------------------------------------------

CREATE TABLE `mails` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Message ID',
  `status` tinyint(4) NOT NULL COMMENT '0: failed 1: success 2:queue',
  `frommail` varchar(128) NOT NULL COMMENT 'Sender Email',
  `fromname` varchar(255) NOT NULL COMMENT 'Sender Name',
  `tomail` varchar(128) NOT NULL COMMENT 'Sent to email',
  `toname` varchar(255) NOT NULL COMMENT 'Sent to name',
  `subject` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `date` int(11) NOT NULL,
  `module` varchar(128) NOT NULL COMMENT 'Module prefix',
  `moduleinfo` varchar(255) NOT NULL COMMENT 'Module Specific Info',
  `extrainfo` text NOT NULL COMMENT 'Extra info',
  `path` varchar(255) NOT NULL,
  `hash` char(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Mail messages history and queue';

-- --------------------------------------------------------

CREATE TABLE `media` (
  `mediaid` int(11) NOT NULL AUTO_INCREMENT,
  `mediatype` int(11) NOT NULL COMMENT '1: image 2:emoticon',
  `userid` int(11) NOT NULL,
  `module" varchar(255) NOT NULL,
  `views` int(11) NOT NULL,
  `thumbnails` text NOT NULL,
  `filesize` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `order` int(11) NOT NULL COMMENT 'for emoticons',
  `name` varchar(255) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `shortcut` varchar(128) NOT NULL COMMENT 'For emoticons - string to add',
  `tags` varchar(255) NOT NULL,
  `date` int(11) NOT NULL COMMENT 'add date',
  `otherusers` tinyint(4) NOT NULL COMMENT 'Viewable by other users',
  `othermodules` tinyint(4) NOT NULL COMMENT 'Viewable by other modules',
  `md5` varchar(32) NOT NULL,
  `medialink` int(11) NOT NULL,
  `usages` int(11) NOT NULL,
  `extrainfo` text NOT NULL,
  PRIMARY KEY (`mediaid`),
  KEY `md5` (`md5`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `mediause` (
  `usageid` int(11) NOT NULL AUTO_INCREMENT,
  `mediaid` int(11) NOT NULL,
  `module` varchar(255) NOT NULL,
  `specific` varchar(255) NOT NULL,
  `date` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `tags` varchar(255) NOT NULL,
  `order` int(11) NOT NULL,
  PRIMARY KEY (`usageid`),
  KEY `mediaid` (`mediaid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `messages` (
  `messageid` mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0: Read Message, 1: New Message, 2: Sent Message, 3: Inbox Archive, 4: Outbox Archive, 5: Unread message, 6: Marked as read, 7: Deleted message, 8: Notification New, 9: Notification Read',
  `subject` varchar(255) NOT NULL DEFAULT '0',
  `text` text NOT NULL,
  `url` varchar(255) NOT NULL,
  `urlcaption` varchar(255) NOT NULL,
  `attachmenttext` text NOT NULL,
  `image` varchar(255) NOT NULL,
  `securitycode` varchar(10) NOT NULL,
  `fromuserid` bigint(20) DEFAULT NULL,
  `touserid` bigint(20) DEFAULT NULL,
  `date` int(11) NOT NULL DEFAULT '0',
  `ip` varchar(15) NOT NULL DEFAULT '',
  `bbcode` tinyint(1) NOT NULL DEFAULT '1',
  `html` tinyint(1) NOT NULL DEFAULT '0',
  `smilies` tinyint(1) NOT NULL DEFAULT '1',
  `signature` tinyint(1) NOT NULL DEFAULT '1',
  `attachment` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`messageid`),
  KEY `privmsgs_from_userid` (`fromuserid`),
  KEY `privmsgs_to_userid` (`touserid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `permissions` (
  `userid` bigint(20) DEFAULT NULL,
  `subject` varchar(80) DEFAULT NULL,
  `resource` varchar(255) NOT NULL DEFAULT '',
  `resourceelement` varchar(255) NOT NULL DEFAULT '',
  `value` tinyint(1) NOT NULL DEFAULT '0',
  `privilege` varchar(80) NOT NULL DEFAULT '',
  `resourcetype` varchar(80) NOT NULL DEFAULT 'module',
  `subjecttype" varchar(80) NOT NULL DEFAULT 'user',
  KEY `userid` (`userid`),
  KEY `resource` (`resource`),
  KEY `resourcetype` (`resourcetype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `scheduledmail` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `time` bigint(20) NOT NULL,
  `data` text NOT NULL,
  `content` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `schemaversion` (
  `when` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `key` varchar(255) NOT NULL,
  `extra` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `sessions` (
  `visitorid` binary(8) NOT NULL,
  `uname` varchar(128) NOT NULL DEFAULT '',
  `time` int(11) NOT NULL,
  `host_addr` varchar(39) NOT NULL DEFAULT '',
  `guest` int(11) NOT NULL DEFAULT '0',
  `agent` varchar(255) NOT NULL,
  `userid` bigint(20) DEFAULT NULL,
  `url` varchar(255) NOT NULL,
  `history` text NOT NULL,
  `logout` tinyint(4) NOT NULL DEFAULT '0',
  `sid` varchar(32) NOT NULL,
  PRIMARY KEY (`visitorid`),
  UNIQUE KEY `host_addr` (`host_addr`, `agent`, `sid`),
  KEY `userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting` varchar(128) NOT NULL DEFAULT '',
  `value` text NOT NULL,
  `delete` tinyint(4) NOT NULL DEFAULT '1',
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting` (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `urls` (
  `urlid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `url` varchar(255) DEFAULT NULL,
  `hash` int(10) UNSIGNED NOT NULL COMMENT 'crc32 hash of the url',
  PRIMARY KEY (`urlid`),
  KEY `index_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `userdetails` (
  `userid` bigint(20) NOT NULL,
  `fieldname` varchar(35) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`userid`, `fieldname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `users` (
  `userid` bigint(20) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL DEFAULT '',
  `password` varchar(100) NOT NULL DEFAULT '',
  `email` varchar(150) NOT NULL DEFAULT '',
  `lastname` varchar(128) NOT NULL DEFAULT '',
  `firstname` varchar(128) NOT NULL DEFAULT '',
  `regdate` int(11) NOT NULL DEFAULT '0',
  `regcompletion` int(10) UNSIGNED DEFAULT NULL,
  `lasttermsagreed` int(10) UNSIGNED DEFAULT NULL,
  `lastlogin` int(11) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `validated` tinyint(4) NOT NULL DEFAULT '1',
  `language` varchar(50) NOT NULL DEFAULT '',
  `timezone` char(3) NOT NULL DEFAULT '',
  `dateformat` varchar(15) NOT NULL DEFAULT 'd/m/Y H:i',
  `usertype` tinyint(4) NOT NULL,
  `sex` tinyint(3) UNSIGNED NOT NULL COMMENT '0: female 1: male',
  `birthdate` bigint(20) NOT NULL,
  `photo` int(11) DEFAULT NULL COMMENT 'usageid',
  `phone` varchar(50) NOT NULL,
  `mobile` varchar(50) NOT NULL,
  `website` varchar(255) NOT NULL,
  `modified` int(11) NOT NULL,
  PRIMARY KEY (`userid`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `photo` (`photo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE `usergroups` (
  `groupid` mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL COMMENT 'Group Name',
  `description` text NOT NULL COMMENT 'Group Description',
  `order` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`groupid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='User Groups';

CREATE TABLE `userstogroups` (
  `userid` bigint(20) NOT NULL,
  `groupid` mediumint(8) UNSIGNED NOT NULL,
  PRIMARY KEY (`userid`,`groupid`),
  KEY `groupid` (`groupid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Users to groups';


CREATE TABLE `usertokens` (
  `tokenid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Token ID',
  `userid` bigint(20) NOT NULL COMMENT 'User ID',
  `tokentype` varchar(20) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Token Type',
  `token` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Token',
  `created` int(10) UNSIGNED NOT NULL COMMENT 'Date created',
  `notes` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Notes',
  `lastused` int(10) UNSIGNED NOT NULL COMMENT 'Date of last use',
  `status` tinyint(3) UNSIGNED NOT NULL COMMENT '0: inactive 1: active 2: removed - will delete',
  `parentToken` int(10) UNSIGNED DEFAULT NULL COMMENT 'Parent Token (will be deleted after parent deletion)',
  `applicationid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Application ID',
  `actions` int(10) UNSIGNED NOT NULL COMMENT 'Number of actions',
  `removedate` int(10) UNSIGNED NOT NULL COMMENT 'Remove date (in unix timestamp)',
  `deviceinfo` blob NOT NULL COMMENT 'User agent info (on login action)',
  `scope` blob NOT NULL COMMENT 'Token Scope',
  PRIMARY KEY (`tokenid`),
  UNIQUE KEY `uniquetoken` (`userid`, `tokentype`, `token`),
  KEY `userid` (`userid`),
  KEY `token` (`token`),
  KEY `parentToken` (`parentToken`),
  KEY `applicationid` (`applicationid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Various user tokens';

-- --------------------------------------------------------

CREATE TABLE `tokenactions` (
  `actionid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary Key',
  `tokenid` int(10) UNSIGNED NOT NULL COMMENT 'Token Id',
  `urlid` int(10) UNSIGNED NOT NULL COMMENT 'URL Id',
  `method` varchar(6) NOT NULL COMMENT 'Request Method',
  `params` blob NOT NULL COMMENT 'Request Options',
  `servertime` int(10) UNSIGNED NOT NULL COMMENT 'Server time (Unix Timestamp)',
  PRIMARY KEY (`actionid`),
  KEY `tokenid` (`tokenid`),
  KEY `urlid` (`urlid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------
-- Foreign Keys
-- --------------------------------------------------------

ALTER TABLE `applications`
  ADD CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`userid`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `mediause`
  ADD CONSTRAINT `mediause_ibfk_1` FOREIGN KEY (`mediaid`) REFERENCES `media` (`mediaid`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`fromuserid`) REFERENCES `users` (`userid`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`touserid`) REFERENCES `users` (`userid`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `permissions`
  ADD CONSTRAINT `permissions_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `tokenactions`
  ADD CONSTRAINT `tokenactions_ibfk_1` FOREIGN KEY (`tokenid`) REFERENCES `usertokens` (`tokenid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `tokenactions_ibfk_2` FOREIGN KEY (`urlid`) REFERENCES `urls` (`urlid`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `userdetails`
  ADD CONSTRAINT `userdetails_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`photo`) REFERENCES `mediause` (`usageid`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `usertokens`
  ADD CONSTRAINT `usertokens_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `usertokens_ibfk_2` FOREIGN KEY (`parentToken`) REFERENCES `usertokens` (`tokenid`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `usertokens_ibfk_3` FOREIGN KEY (`applicationid`) REFERENCES `applications` (`appid`) ON DELETE SET NULL ON UPDATE CASCADE;


ALTER TABLE `userstogroups`
  ADD CONSTRAINT `userstogroups_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `userstogroups_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `usergroups` (`groupid`) ON DELETE CASCADE ON UPDATE CASCADE;



COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
