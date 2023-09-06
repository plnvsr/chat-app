import requests 
import subprocess 
import time 
import sqlite3

# Small Python script for testing 
# Feel free to edit 

local_host = "localhost:8080" # modify this with your choice
php_server_command = 'php -S ' + local_host
php_process = subprocess.Popen(php_server_command, shell=False)
conn = sqlite3.connect('chat.db')

# Some example data I wrote for testing
groups_data = [{'id': 1, 'groupname': 'Sentinels'}, 
                  {'id': 2, 'groupname': 'Duelists'}, 
                  {'id': 3, 'groupname': 'Controllers'}]

sent_message = {'message' : 'hi from tester'}

created_group = {'groupname' : 'Nerfed'}

header_user1 = {'Authorization': 
                'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6MSwidXNlcm5hbWUiOiJKZXR0In0.4TKX-7GUwPZS5zFiUtQGmzah0bWep4HVQ9duZhw_hWE'}

overall = True 

failed_tests = []

# Wait for the server to start 
time.sleep(1)

# Perform testing using requests library
def tester(endpoint, type, expected_status, headers = None, sent_data = None, compare_data = None, compare_col = None):
    print('TESTER: Testing ' + endpoint + ' ' + type)
    result = False 

    url = 'http://' + local_host + endpoint
    if type == 'get':
        response = requests.get(url, headers=headers) 
    elif type == 'post':
        response = requests.post(url, headers=headers, data=sent_data)
    else: 
        response = requests.delete(url, headers=headers, data=sent_data)
    
    # This part checks if the post request was successful
    if response.status_code == expected_status:
        if compare_data is not None:
            if any(msg[compare_col] == compare_data for msg in response.json()):
                result = True
        else: 
            result = True 
    
    if response.status_code == 200 and type == 'get':
        print(response.json())

    return result 


def tester_printer(case_no, result): 
    if result: 
        print('TESTER: Case ' + str(case_no) + ' Success')
    else: 
        print('TESTER: Case ' + str(case_no) + ' Failed')
        overall = False
        failed_tests.append(case_no)



# case 1 : basic groups get 
tester_printer(1, tester('/groups', 'get', 200))

# case 2: invalid user token 
tester_printer(2, tester('/groups/1/messages', 'get', 401, headers = {'Authorization': 'Bearer 09x897da82713sgag'}))

# case 3: valid token, user not a member 
tester_printer(3, tester('/groups/1/messages', 'get', 403, headers = header_user1))

# case 4: valid group get 
tester_printer(4, tester('/groups/2/messages', 'get', 200, headers = header_user1))

# case 5: send a message to group 
tester_printer(5, tester('/groups/2/messages', 'post', 201, headers = header_user1, sent_data=sent_message))

# case 6: retrieve the same group and compare
tester_printer(6, tester('/groups/2/messages', 'get', 200, headers = header_user1, compare_data='hi from tester', compare_col='message'))

# revert db 
query = "DELETE FROM messages WHERE message = ?"
conn.execute(query, (sent_message['message'],))
conn.commit()

# case 7: joining a group user is already a member
tester_printer(7, tester('/groups/2/join', 'post', 403, headers= header_user1))

# case 8: joining a group user is not a member 
tester_printer(8, tester('/groups/1/join', 'post', 201, headers=header_user1))

# case 9: getting the messages user just joined
tester_printer(9, tester('/groups/1/messages', 'get', 200, headers=header_user1, compare_data='Hi fellow Sentinels', compare_col='message'))
 
# case 10: create a new group 
tester_printer(10, tester('/groups/create', 'post', 201, headers=header_user1, sent_data=created_group)) 

# case 11: check if the created group is present 
tester_printer(11, tester('/groups', 'get', 200, compare_data='Nerfed', compare_col='groupname'))

# get the group id of the group ' Nerfed ' 
query = 'SELECT id FROM groups WHERE groupname = ?'
result = conn.execute(query, (created_group['groupname'],))
group_id = str(result.fetchone()[0])

# case 12: check if the user is a member of the group that's just created 
tester_printer(12, tester('/groups/' + group_id + '/messages', 'get', 200, headers=header_user1))

# case 13: leave a group
tester_printer(13, tester('/groups/1/leave', 'delete', 204, headers=header_user1))

# case 14: leave a group x2 
tester_printer(14, tester('/groups/' + group_id + '/leave', 'delete', 204, headers=header_user1))

# case 15: check if the user is a member of the group they're just left 
tester_printer(15, tester('/groups/1/messages', 'get', 403, headers=header_user1))

# revert db 
query = 'DELETE FROM groups WHERE groupname = ? '
conn.execute(query, (created_group['groupname'],))
conn.commit()

if overall: 
    print('TESTER: All tests passed.')
else: 
    print('TESTER: Review the tests: ')
    print(failed_tests)

# Terminate the PHP server subprocess
print('TESTER: Terminating PHP server.')
php_process.terminate()