# SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
Feature: logging

  Scenario: ensure storage credentials are not leaked to nextcloud.log
    Given a dummy storage with login "alice@sharepoint.test" and password "53cr371v3"
    When verifying the latest created storage (ignoring the result)
    Then the string "53cr371v3" must not appear in the nextcloud.log
