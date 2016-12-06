(function($) {
    var app = angular.module('app', ['infinite-scroll']); 
    //directive that cheks if an image is loaded
    /*app.directive('imageonload', function() {
        return {
            restrict: 'A',
            link: function(scope, element, attrs) {
                element.bind('load', function() {
                    return true;
                });
                element.bind('error', function(){
                    return false;
                });
            }
        };
    });*/

    app.controller('pictureController', ['$scope', function($scope) {
        $scope.data =[];
        $scope.masterArray =[];
        $scope.orginalArray =[]; // The purpose of this array is to store the original ayout ofthe masterArray
        //temporary function that creates fake data in the same format as I expect to get the data though an API
        var getFakeData = function(){
            var data = {};           
            for (var i = 0; i < 1000; i++){
                if ((i%4) == 0){
                   data[i] = { url: "/img/200.jpeg" }; //"http://loremflickr.com/200/200?random="+i 
                }
                else if ((i%2) == 0){
                    data[i] = { url: "/img/350.png" };
                }
                else if ((i%3) == 0){
                    data[i] = { url: "/img/1000.png" };
                }
                else {
                    data[i] = { url: "/img/2000.jpeg" };
                }
                
                if ((i%2)==0){
                    data[i]["socialPlatform"] = "FB"
                    data[i]["likes"] = 10000 + (i)*2
                    data[i]["party"] = "PPSI"
                    data[i]["partyName"] = "Pirate party of Slovenia"
                    data[i]["code"] = "si"
                }
                else {
                    data[i]["socialPlatform"] = "TW"
                    data[i]["likes"] = 10000 + (-i)*2
                    data[i]["party"] = "PPAT-NOE"
                    data[i]["partyName"] = "Pirate party of Austria"
                    data[i]["code"] = "at"
                };
            };
            return data;
    	};
        //fake data with solely the party names
       $scope.partyList = {};
        $scope.partyList = {0:{partyName:"Pirate Party of Slovenia", party:"PPSI"},1:{partyName:"Pirate Party of Austria", party:"PPAT-NOE"}};

        //this functions loads more data for the infinite scroll
        // it constantily updates the array from which ng-rpeat gets its data
        $scope.loadMore = function() {
            var last = [];
            var x
            if ( $scope.data.length == 0){
                x = 0
            }
            else {
                x = $scope.data.length - 1;
            }
            for(var i = 1; i <= 20; i++) {
                var currentValue = $scope.masterArray[x+i];        
                $scope.data.push(currentValue);
            }
        };

        $.when(getFakeData()).done(function(data){ 
            for (var x in data){
                $scope.masterArray.push(data[x]); 
            };
            $scope.originalArray = $scope.masterArray.slice();
            $scope.loadMore(); 

        });

        //Function that runs after data is acquired
        //it transforms the object into an array and runs 


        
        //function that sorts entries by reach in ascending order
        $scope.sortAscViews = function(){ 
            $scope.masterArray.sort(function(a, b){
            return a.likes > b.likes;
        });
        $scope.data = [];
        $scope.loadMore();
        };
        //function that sorts entries by reach in descending order
        $scope.sortDescViews = function(){
            $scope.masterArray.sort(function(a, b){
            return a.likes < b.likes;
        });
        $scope.data = [];
        $scope.loadMore();
        };

        $scope.defaultSort = function(){
        $scope.masterArray = $scope.originalArray.slice(); 
        $scope.data = [];
        $scope.loadMore(); 
        $(".up").removeClass("arrow-color")
        $(".down").removeClass("arrow-color")
        }

        //function that toggles between ascending and descending amount of reach
        $scope.sortByViews = function(){
            if ( $("#asc-desc-views").hasClass("desc") ) {
                $("#asc-desc-views").removeClass("desc")
                $("#asc-desc-views").addClass("asc")
                $scope.sortAscViews()
            }
            else {
                $("#asc-desc-views").addClass("toggled")
                $("#asc-desc-views").addClass("desc")
                $("#asc-desc-views").removeClass("asc")
                $scope.sortDescViews();
            };

        } 

        $('#platform-selection').bind('click', function (e) { e.stopPropagation() })

    }]);
})(jQuery); // end of jQuery name space