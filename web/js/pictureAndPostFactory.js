angular.module("app").factory("pictureAndPostFactory", function($http)
{
    var pictureAndPostFactory = {
        partyList : function(){
            var promise = $http.get('http://api.piratetimes.net/api/v1/parties/?_format=json')
            .then(function(response) {
                //First function handles success
                return response.data;
            }, function(response) {
                //Second function handles error
                console.log("error in acquring party list")
        });  
            return promise
        }, 
        imageList : function(){
            var promise = $http.get('http://api.piratetimes.net/api/v1/social/?_format=json&sub_type=I')
            .then(function(response) {
                //First function handles success
                return response.data;
            }, function(response) {
                //Second function handles error
                console.log("error in acquring party list")
        });  
            return promise
        }
    };
return pictureAndPostFactory;
});