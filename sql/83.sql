ALTER TABLE `games` ADD `ensure_events_future_rounds` INT NOT NULL DEFAULT '0' AFTER `events_until_block`;