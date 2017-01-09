angular.module("app").factory("pictureAndPostFactory", function($http)
{ 	var partyList = []
	var partyImages = []
	$http.get('http://api.piratetimes.net/api/v1/parties/')
	.then(function(response) {
        //First function handles success
        console.log(response.data);
         partyList = Object.values(response.data);
    }, function(response) {
        //Second function handles error
        console.log("error in acquring party list")
    });

    $http.get('http://api.piratetimes.net/api/v1/social/?_format=json&sub_type=i')
	.then(function(response) {
        //First function handles success
        
        partyImages = Object.values(response.data);
        console.log(partyImages);
    }, function(response) {
        //Second function handles error
        console.log("error in acquring party list")
    });

	return {
		partyImages: partyImages, partyList : partyList
	};
});