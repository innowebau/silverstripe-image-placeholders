# Changelog

All notable changes to this project will be documented in this file.

This project adheres to [Semantic Versioning](http://semver.org/).

## [3.0.1]

* fix remove obsolete variable

## [3.0.0]

* make LCP LQIP use new SS functionality of saving variations with different extensions. The LCPLQIP() method doesn't 
have the format parameter anymore. Format changes can now be chained using 
[tractorcow/silverstripe-image-formatter](https://github.com/tractorcow/silverstripe-image-formatter). 

## [2.1.5]

* improve performance of LCP LQIP

## [2.1.4]

* fix dependencies

## [2.1.3]

* fix typo

## [2.1.2]

* add missing dependency

## [2.1.1]

* fix LCP LQIP method, including format conversion in LQIP method because chaining is not possible for files with 
different extension

## [2.1.0]

* add LCP LQIP method

## [2.0.0]

* upgrade to Silverstripe 5

## [1.0.2]

* increase memory limit for DataURL calls

## [1.0.1]

* attempt at fixing memory issues by unsetting variables

## [1.0.0]

* initial release
