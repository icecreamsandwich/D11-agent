# Campaign Winner Selection

## Introduction

The Campaign Winner Selection module provides functionality for selecting and managing winners for campaigns. It includes forms for selecting winners based on various criteria, uploading coupon codes and user details via webforms, and sending email notifications to the selected winners.

## Requirements

- Drupal 8.x
- Webform module

## Installation

1. Download the module to your Drupal site's `modules/custom` directory.
2. Enable the module through the Drupal admin UI or using Drush:
   ```bash
   drush en campaign_winner_selection

## Configuration
No additional configuration is required. Ensure that you have the necessary webforms set up for uploading coupon codes and users.

## Usage

### Selecting Winners
Navigate to the Select Winners form under the Campaign Winner Selection section.
Select the appropriate campaign, event type, number of users, and country.
Click 'Select Winners' to randomly choose winners based on the specified criteria.

### Uploading Coupon Codes
Navigate to the /form/campaign-upload-coupon-codes URL.
Upload the CSV file containing the coupon codes.

The CSV file should have the following format:
Campaign Name,Coupon Code
Campaign A,EEPM124


### Uploading Users
Navigate to the /form/campaign-upload-users URL.
Upload the CSV file containing the user details.
The CSV file should have the following format:

first_name,last_name,email,country
John,Doe,john.doe@example.com,United States


### Sending Emails to Winners
After selecting the winners, you will be redirected to a confirmation form.
Confirm the email addresses of the winners and click 'Submit' to send the emails.
