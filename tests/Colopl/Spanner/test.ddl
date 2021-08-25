CREATE TABLE `Test` (
  `testId` STRING(36) NOT NULL,
  `uniqueStringTest` STRING(MAX) NOT NULL,
  `stringTest` STRING(MAX) NOT NULL,
  `nullableStringTest` STRING(MAX),
  `intTest` INT64 NOT NULL,
  `nullableIntTest` INT64,
  `floatTest` FLOAT64 NOT NULL,
  `nullableFloatTest` FLOAT64,
  `timestampTest` TIMESTAMP NOT NULL,
  `nullableTimestampTest` TIMESTAMP,
  `dateTest` DATE NOT NULL,
  `nullableDateTest` DATE,
  `bytesTest` BYTES(MAX) NOT NULL,
  `nullableBytesTest` BYTES(MAX),
) PRIMARY KEY (`testId`);

CREATE UNIQUE INDEX `Test_uniqueStringTest` ON `Test`(`uniqueStringTest`);

CREATE TABLE `User` (
  `userId` STRING(36) NOT NULL,
  `name` STRING(255) NOT NULL,
) PRIMARY KEY (`userId`);

CREATE TABLE `UserItem` (
  userId STRING(36) NOT NULL,
  userItemId STRING(36) NOT NULL,
  itemId STRING(36) NOT NULL,
  count INT64 NOT NULL,
) PRIMARY KEY(userId, userItemId),
  INTERLEAVE IN PARENT `User` ON DELETE CASCADE;

CREATE TABLE `Item` (
    `itemId` STRING(36) NOT NULL,
    `name` STRING(100) NOT NULL,
  ) PRIMARY KEY(`itemId`);

CREATE TABLE `ItemTag` (
  `itemId` STRING(36) NOT NULL,
  `tagId` STRING(36) NOT NULL,
) PRIMARY KEY(`itemId`);

CREATE TABLE `Tag` (
  `tagId` STRING(100) NOT NULL,
) PRIMARY KEY(`tagId`);

CREATE TABLE `UserInfo` (
  userId STRING(36) NOT NULL,
  userInfoId STRING(36) NOT NULL,
  rank INT64 NOT NULL,
) PRIMARY KEY(userId, userInfoId),
  INTERLEAVE IN PARENT `User` ON DELETE CASCADE;

CREATE TABLE `ArrayTest` (
  `arrayTestId` STRING(36) NOT NULL,
  `int64Array` ARRAY<INT64> NOT NULL,
) PRIMARY KEY (`arrayTestId`);
