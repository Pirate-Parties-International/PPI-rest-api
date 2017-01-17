angular.module("app").factory("pictureAndPostFactory", function($http)
{
    var pictureAndPostFactory = {
        partyList : function(){
            var promise = $http.get('http://api.piratetimes.net/api/v1/parties/?_format=json&show_defunct=false')
            .then(function(response) {
                //First function handles success
                return response.data;
            }, function(response) {
                //Second function handles error
                console.log("error in acquring party list")
        });  
            return promise
        }, 
        imageList : function(x){
            var promise = $http.get("http://api.piratetimes.net/api/v1/social/?_format=json&sub_type=I"+"&order_by="+x.sort+"&code="+x.partyCode+"&offset="+x.offset)
            .then(function(response) {
                //First function handles success
                console.log("http://api.piratetimes.net/api/v1/social/?_format=json&sub_type=I"+"&order_by="+x.sort+"&code="+x.partyCode+"&offset="+x.offset)
                return response.data;
            }, function(response) {
                //Second function handles error
                console.log("error in acquring image list")
        });  
            return promise
        }
    };
return pictureAndPostFactory;
});