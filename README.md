## WP REST API for Updating, Creating docs pages for doc Post type.

This API is open for sepecfic user (Login Based). And its able to accept POST request to an endpoint(http://xyz.com/awesome-api/v1/docs/document) with JSON body.

JSON body contains the markup for the page content and destination file path.

**For Updating Docs page:** Based on operations It will copy the JSON body (as it is without any parsing) to its destination file path.

**For Creating Docs page:** It will create a new page but in a draft state (for creation).

Above all, it will also maintain the Revision of the page based on update, create.


## Note: 
Feel free to modify the coding logic based on your requirement.
I have few dependency of the dropdown checks, so hence my code length. You should have your own. 

**Happy Coding :)**