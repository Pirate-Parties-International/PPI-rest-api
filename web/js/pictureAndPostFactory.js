angular.module("app").factory("pictureAndPostFactory", function($http)
{
	return $http.get('http://api.piratetimes.net/api/v1/parties/')
	.then(function(response) {
        //First function handles success
        return response.data;
    }, function(response) {
        //Second function handles error
        console.log("error in acquring party list")
    });;

});