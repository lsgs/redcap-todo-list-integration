********************************************************************************
# To-Do List Integration

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au
[https://github.com/lsgs/redcap-todo-list-integration](https://github.com/lsgs/redcap-todo-list-integration)

## Summary

REDCap external module for extending the Control Center To-Do List with requests from records originating in projects where the module is enabled and configured.

## Functionality: System-Level

This module acts on the Control Center To-Do List pageand performs the following functions:
- When viewing a to-do list request detail (via the (i) icon)
  - Removes the hover style and click handler from the comment text display
  - Parses the comment text display and converts any URLs into clickable hyperlinks
- Provides a custom default page for marking to-do list requests "Complete"

## Functionality: Project-Level

When enabled in a project, this module can be configured to have to-do list requests generated from project records records.

### Project Configuration

- Trigger form(s): Evaluate trigger condition on save of this form or forms.
- Trigger logic: Logic expression that when true will cause a to-do list request to be generated.
- **Request ID Field** (required): Field in which to store the to-do list request id key value generated for this record.
- **Request From** (required): Field containing username or user-id (ui_id) of user originating the request. A valid username or id is required for To-Do List requests to be created.
- Request To: Field containing email address for to-do list notification.
- **To-Do Type** (required): Field containing type/category text for request.
- To-Do Type ID: Field containing type/category id for request. Generally NULL/empty; if specified, must be unique for project and to-do type.
- Action URL: Field containing the URL to launch in the To-Do list page. Leave empty to utilise the module's built-in, default Request Completion page. (If specified, will get `&request_id=n` appended in to-do list.)
- Project ID: Field containing Project ID for the request.
- To-Do Comment: Field containing comment text for the request.
- Failure Email(s): Email addresses for failure alerts should request generation fail.

********************************************************************************