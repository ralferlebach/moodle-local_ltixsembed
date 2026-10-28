@local @local_ltixsembed
Feature: LTI Cross-Site Embed administration and the new-window fallback
  In order to keep embedded LTI tools usable when the browser blocks third-party storage
  As a platform administrator and as a learner
  I need the diagnostics, the configuration audit and an always-available new-window option

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: The plugin is installed
    Given I log in as "admin"
    And I navigate to "Plugins > Plugins overview" in site administration
    Then I should see "LTI Cross-Site Embed"

  Scenario: A learner sees the new-window option on an activity that is embedded in an iframe
    Given the following "activities" exist:
      | activity | name      | course | idnumber | toolurl                         | launchcontainer |
      | lti      | Embedded  | C1     | lti1     | https://tool.example.org/launch | 2               |
    When I am on the "Embedded" "lti activity" page logged in as student1
    Then I should see "Trouble loading the tool? Open it in a new window"
    And "Open the tool in a new window" "button" should exist
    And the "action" attribute of "form[data-region='fallback-form']" "css_element" should contain "/local/ltixsembed/fallback.php"
    And the "action" attribute of "form[data-region='fallback-form']" "css_element" should not contain "nonce"
    And the "action" attribute of "form[data-region='fallback-form']" "css_element" should not contain "sesskey"
    And the "method" attribute of "form[data-region='fallback-form']" "css_element" should contain "post"
    # A plain GET (reload, back button, bookmark) gets a normal page with a notice, not an exception page, and launches nothing.
    When I visit "/local/ltixsembed/fallback.php?cmid=1"
    Then I should see "This action must be sent as a form (POST)."
    And "Back to the activity" "button" should exist

  Scenario: No new-window option on an activity that already opens in its own window
    Given the following "activities" exist:
      | activity | name      | course | idnumber | toolurl                         | launchcontainer |
      | lti      | Windowed  | C1     | lti2     | https://tool.example.org/launch | 4               |
    When I am on the "Windowed" "lti activity" page logged in as student1
    Then "[data-region='local_ltixsembed-notice']" "css_element" should not exist

  Scenario: The fallback form leads to the ordinary launch of the activity
    Given the following "activities" exist:
      | activity | name      | course | idnumber | toolurl                         | launchcontainer |
      | lti      | Embedded  | C1     | lti1     | https://tool.example.org/launch | 2               |
    And I am on the "Embedded" "lti activity" page logged in as student1
    When I press "Open the tool in a new window"
    Then the url should match "/mod/lti/launch\.php\?id=\d+$"
    And I should not see "no longer valid"

  Scenario: Administrators see the capability test and the configuration audit
    Given I log in as "admin"
    When I visit "/local/ltixsembed/test.php"
    Then I should see "Browser capability test"
    And I should see "Cookie write and read-back"
    And I should see "LTI platform storage (postMessage)"
    When I visit "/local/ltixsembed/audit.php"
    Then I should see "Embedding configuration audit"
    And I should see "Findings"
    And I should see "Effective settings"
    And I should see "Allow frame embedding"

  Scenario: Learners and teachers get no administration entry for the diagnostics
    # Opening test.php / audit.php without the capability ends in an exception page, which Behat cannot assert.
    # The denial is covered by PHPUnit (local_ltixsembed settings_test / config_audit_test); here the visible navigation is checked.
    Given I log in as "student1"
    When I am on site homepage
    Then I should not see "Site administration"
    And I should not see "Browser capability test"
    When I log out
    And I log in as "teacher1"
    And I am on site homepage
    Then I should not see "Site administration"
    And I should not see "Embedding configuration audit"

  Scenario: Only bare https origins can be configured
    Given I log in as "admin"
    And I visit "/admin/settings.php?section=local_ltixsembed"
    When I set the field "Trusted peer origins" to "https://*.evil.example.net"
    And I press "Save changes"
    Then I should see "Not a valid origin"
    When I set the field "Trusted peer origins" to "https://tool.example.org/some/path"
    And I press "Save changes"
    Then I should see "Not a valid origin"
    When I set the field "Trusted peer origins" to "https://tool.example.org"
    And I press "Save changes"
    Then I should see "Changes saved"

  # Unchecking an admin checkbox is not reliably posted by the non-JS (BrowserKit) driver because of the hidden
  # companion field with the same name; the stored value is therefore set through the config step.
  Scenario: The permission button for the tool side is on by default and reflects a switched-off value
    Given I log in as "admin"
    And I visit "/admin/settings.php?section=local_ltixsembed"
    Then the field "Offer a permission button (Storage Access API)" matches value "1"
    And the following config values are set as admin:
      | storageaccess | 0 | local_ltixsembed |
    When I visit "/admin/settings.php?section=local_ltixsembed"
    Then the field "Offer a permission button (Storage Access API)" matches value ""

  Scenario: The bridge on the tool side is not required by default and can be declared required
    Given I log in as "admin"
    And I visit "/admin/settings.php?section=local_ltixsembed"
    Then the field "Bridge on the tool side" matches value "Not required: a silent tool is normal"
    When I set the field "Bridge on the tool side" to "Required: a silent tool means a blocked frame"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the field "Bridge on the tool side" matches value "Required: a silent tool means a blocked frame"

  Scenario: The capability test page shows the operating mode of the site
    Given I log in as "admin"
    When I visit "/local/ltixsembed/test.php"
    Then I should see "Role of this site"
    And I should see "Platform side and tool side enabled"
    And the following config values are set as admin:
      | consumerenabled | 0 | local_ltixsembed |
    When I visit "/local/ltixsembed/test.php"
    Then I should see "Tool side only (provider-only)"
    And the following config values are set as admin:
      | providerenabled | 0 | local_ltixsembed |
    When I visit "/local/ltixsembed/test.php"
    Then I should see "Inactive: nothing is added to any page"

  Scenario: An inactive site adds nothing to an embedded activity
    Given the following "activities" exist:
      | activity | name      | course | idnumber | toolurl                         | launchcontainer |
      | lti      | Embedded  | C1     | lti1     | https://tool.example.org/launch | 2               |
    And the following config values are set as admin:
      | consumerenabled | 0 | local_ltixsembed |
      | providerenabled | 0 | local_ltixsembed |
    When I am on the "Embedded" "lti activity" page logged in as student1
    Then "[data-region='local_ltixsembed-notice']" "css_element" should not exist

  @javascript
  Scenario: The capability test runs in the browser and reports a state
    Given I log in as "admin"
    When I visit "/local/ltixsembed/test.php"
    Then I should not see "testing..." in the "[data-region='local_ltixsembed-diagnostics']" "css_element"
    And "[data-region='state'][data-lxs-state-value]" "css_element" should exist
