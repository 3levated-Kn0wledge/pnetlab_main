-- PNETLab application schema (pnetlab_db).
--
-- Provenance: extracted with `mysqldump --no-data` from a PNETLab 5.3.13
-- appliance. Upstream ships this inside the appliance image and has never
-- published it separately, so it is reproduced here to make the installer
-- self-sufficient. It is table structure, not code.
--
-- Structure only: no rows. In particular there is no users table content, so no
-- credentials are carried here; install/sql/seed-admin.sql creates the initial
-- administrator instead.
--
-- Note for anyone auditing this: user_roles and user_permission are empty on a
-- stock appliance too, which is why includes/functions.php has to tolerate a
-- null role. That is upstream behaviour, not an incomplete dump.


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `control`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `control` (
  `control_name` varchar(150) NOT NULL,
  `control_value` text,
  PRIMARY KEY (`control_name`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `html5`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `html5` (
  `username` text,
  `pod` int(11) DEFAULT NULL,
  `token` text
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `if_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `if_sessions` (
  `if_session_id` bigint(15) NOT NULL AUTO_INCREMENT,
  `if_session_lab` int(11) DEFAULT NULL,
  `if_session_node` int(11) DEFAULT NULL,
  `if_session_ifid` int(11) DEFAULT NULL,
  `if_session_type` varchar(150) DEFAULT NULL,
  `if_session_quality` text,
  `if_session_suspend` int(11) DEFAULT NULL,
  PRIMARY KEY (`if_session_id`),
  KEY `if_session_ifid` (`if_session_ifid`),
  KEY `if_session_type` (`if_session_type`),
  KEY `if_session_suspend` (`if_session_suspend`),
  KEY `if_session_lab` (`if_session_lab`) USING BTREE,
  KEY `if_session_node` (`if_session_node`) USING BTREE,
  CONSTRAINT `if_sessions_ibfk_1` FOREIGN KEY (`if_session_node`) REFERENCES `node_sessions` (`node_session_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lab_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lab_sessions` (
  `lab_session_id` int(11) NOT NULL AUTO_INCREMENT,
  `lab_session_lid` varchar(150) DEFAULT NULL,
  `lab_session_pod` int(11) DEFAULT NULL,
  `lab_session_joined` text,
  `lab_session_path` text,
  `lab_session_running` int(11) DEFAULT NULL,
  PRIMARY KEY (`lab_session_id`) USING BTREE,
  KEY `lab_session_lid` (`lab_session_lid`) USING BTREE,
  KEY `lab_session_pod` (`lab_session_pod`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `node_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `node_sessions` (
  `node_session_id` int(11) NOT NULL,
  `node_session_nid` int(11) DEFAULT NULL,
  `node_session_lab` int(11) DEFAULT NULL,
  `node_session_port` int(11) DEFAULT NULL,
  `node_session_type` varchar(150) DEFAULT NULL,
  `node_session_workspace` text,
  `node_session_ram` float DEFAULT NULL,
  `node_session_cpu` float DEFAULT NULL,
  `node_session_hdd` float DEFAULT NULL,
  `node_session_running` int(1) DEFAULT NULL,
  `node_session_pod` int(11) DEFAULT NULL,
  `node_session_iol` int(11) DEFAULT NULL,
  PRIMARY KEY (`node_session_id`) USING BTREE,
  UNIQUE KEY `node_session_nid_2` (`node_session_nid`,`node_session_lab`),
  KEY `node_session_lab` (`node_session_lab`),
  KEY `node_session_port` (`node_session_port`),
  KEY `node_session_nid` (`node_session_nid`),
  KEY `node_session_type` (`node_session_type`),
  KEY `node_session_running` (`node_session_running`),
  KEY `node_session_pod` (`node_session_pod`),
  KEY `node_session_iol` (`node_session_iol`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `process`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `process` (
  `process_id` varchar(200) NOT NULL,
  `process_dtotal` int(11) DEFAULT NULL,
  `process_dnow` int(11) DEFAULT NULL,
  `process_utotal` int(11) DEFAULT NULL,
  `process_unow` int(11) DEFAULT NULL,
  `process_finish` int(11) DEFAULT NULL,
  PRIMARY KEY (`process_id`),
  KEY `process_dtotal` (`process_dtotal`),
  KEY `process_dnow` (`process_dnow`),
  KEY `process_utotal` (`process_utotal`),
  KEY `process_unow` (`process_unow`),
  KEY `process_finish` (`process_finish`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `process_device`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `process_device` (
  `process_device_id` varchar(150) NOT NULL,
  `process_device_dtotal` int(11) DEFAULT NULL,
  `process_device_dnow` int(11) DEFAULT NULL,
  `process_device_utotal` int(11) DEFAULT NULL,
  `process_device_unow` int(11) DEFAULT NULL,
  `process_device_log` text,
  UNIQUE KEY `process_device_id` (`process_device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permission` (
  `user_per_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_per_role` int(11) DEFAULT NULL,
  `user_per_name` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`user_per_id`),
  KEY `user_per_role` (`user_per_role`),
  KEY `user_per_name` (`user_per_name`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_roles` (
  `user_role_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_role_name` varchar(150) CHARACTER SET utf8 DEFAULT NULL,
  `user_role_workspace` text CHARACTER SET utf8,
  `user_role_note` text CHARACTER SET utf8,
  `user_role_ram` float DEFAULT NULL,
  `user_role_cpu` float DEFAULT NULL,
  `user_role_hdd` float DEFAULT NULL,
  PRIMARY KEY (`user_role_id`),
  KEY `user_role_name` (`user_role_name`),
  KEY `user_role_ram` (`user_role_ram`),
  KEY `user_role_cpu` (`user_role_cpu`),
  KEY `user_role_hdd` (`user_role_hdd`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `pod` int(11) NOT NULL AUTO_INCREMENT,
  `username` text CHARACTER SET utf8,
  `cookie` text CHARACTER SET utf8,
  `email` varchar(150) CHARACTER SET utf8 DEFAULT NULL,
  `expiration` int(11) DEFAULT '-1',
  `name` text CHARACTER SET utf8,
  `password` text CHARACTER SET utf8,
  `session` int(11) DEFAULT NULL,
  `ip` text CHARACTER SET utf8,
  `role` text CHARACTER SET utf8,
  `folder` text CHARACTER SET utf8,
  `lab_session` int(11) DEFAULT NULL,
  `html5` tinyint(1) DEFAULT NULL,
  `license` text CHARACTER SET utf8,
  `online_time` int(11) DEFAULT NULL,
  `note` text CHARACTER SET utf8,
  `offline` int(1) DEFAULT NULL,
  `active_time` int(11) DEFAULT NULL,
  `expired_time` int(11) DEFAULT NULL,
  `user_status` int(2) DEFAULT '1',
  `user_workspace` text CHARACTER SET utf8,
  `max_node` int(11) DEFAULT NULL,
  `max_node_lab` int(11) DEFAULT NULL,
  PRIMARY KEY (`pod`),
  UNIQUE KEY `email` (`email`),
  KEY `online_time` (`online_time`),
  KEY `lab_session` (`lab_session`),
  KEY `offline` (`offline`),
  KEY `active_time` (`active_time`),
  KEY `expired_time` (`expired_time`),
  KEY `user_status` (`user_status`),
  KEY `max_node` (`max_node`),
  KEY `max_node_lab` (`max_node_lab`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wiresharks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wiresharks` (
  `ws_id` bigint(15) NOT NULL AUTO_INCREMENT,
  `ws_tenant` int(11) DEFAULT NULL,
  `ws_lab` varchar(200) DEFAULT NULL,
  `ws_node` int(11) DEFAULT NULL,
  `ws_if` int(11) DEFAULT NULL,
  `ws_net` int(11) DEFAULT NULL,
  `ws_node_name` varchar(150) DEFAULT NULL,
  `ws_if_name` varchar(150) DEFAULT NULL,
  `ws_dc_name` varchar(150) DEFAULT NULL,
  `ws_port` int(11) DEFAULT NULL,
  `ws_ip` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`ws_id`),
  KEY `ws_ip` (`ws_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

